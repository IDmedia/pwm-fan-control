<?php
/*
 * PWM Fan Control - the control loop run by scripts/daemon.php.
 *
 * Every <interval> seconds tick() reads the temperature sources, works out the speed for every fan
 * group and applies it. Speeding up happens right away. Slowing down only happens once the lower
 * speed has been wanted for <ramp_down> seconds (the group keeps the highest speed asked for in that
 * window), so short temperature dips don't make the fans pulse.
 *
 * Safety rules
 *   - A source that can't be read on two checks in a row makes its groups run at full speed.
 *   - An error in the loop itself sets every controlled fan to full speed and is retried next check.
 *   - A crash of the service leaves the fans at full speed until the watchdog restarts it.
 *   - Only a deliberate stop (disabled, removed, signal) hands the fans back to the BIOS.
 *
 * It also watches for problems and reports them as warnings (status page, syslog, Unraid alert
 * notifications):
 *   - a fan that stays at 0 RPM while it should spin (needs a confirmed RPM sensor),
 *   - a fan that runs clearly faster or slower than its speed test measured at the same speed, which
 *     usually means a different fan is now connected to that header (fans were moved),
 *   - a fan whose speed or mode is changed by something else (another fan control),
 *   - fans that can't be found or written (driver not loaded, hardware changed),
 *   - temperature sources that can't be read, and errors of the service itself.
 */
final class PfcController {
    private const SOURCE_RETRIES = 1;     // failed reads of a source tolerated before full speed
    private const STALL_SECONDS  = 20;    // 0 RPM for this long while it should spin = stalled
    private const STALL_MIN_PWM  = 77;    // 30%: below this, without a speed test, 0 RPM may be normal
    private const SETTLE_SECONDS = 15;    // time a fan needs to reach its speed after a change
    private const MISMATCH_SECONDS = 60;  // RPM far from the speed test for this long = different fan
    private const MISMATCH_LOW   = 0.65;  // RPM below 65% ...
    private const MISMATCH_HIGH  = 1.5;   // ... or above 150% of the speed test = mismatch
    private const CONFLICT_TICKS = 2;     // checks in a row a fan has to be changed by someone else
    private const NOTIFY_AFTER   = 60;    // seconds a warning has to persist before a notification
    private const RESOLVE_SECONDS = 120;  // a warning has to be gone this long to count as resolved
    private const REALERT_AFTER  = 14400; // the same warning is alerted at most once in 4 hours

    private ?array $cfg = null;
    private ?string $cfgHash = null;
    private array $cfgErrors = [];
    private array $fans = [];
    private ?string $fansHash = null;

    private array $windows = [];      // group id => [[time, pwm], ...] for the slow-down delay
    private array $logged = [];       // group id => last logged pwm
    private array $lastGood = [];     // source id => last good reading
    private array $failures = [];     // source id => failed reads in a row
    private array $written = [];      // fan key => [pwm we set, enable we set]
    private array $conflicts = [];    // fan key => checks in a row changed by someone else
    private array $zeroSince = [];    // fan key => time it was first seen at 0 RPM while it should spin
    private array $setAt = [];        // fan key => [time, pwm] of the last real speed change
    private array $mismatchSince = []; // fan key => time its RPM was first seen far from the speed test
    private ?array $hwCheck = null;   // pfc_hardware_check() while fans are missing, refreshed every minute
    private array $lastWarnings = []; // warnings of the last good check
    private array $alerts;            // see report()
    private int $lastHistory = 0;

    public bool $running = true;

    public function __construct() {
        $this->alerts = self::loadAlerts();
    }

    /** Runs until stopped or disabled, then hands the fans back to the BIOS. */
    public function run(): void {
        pfc_log('Started');
        while ($this->running) {
            $start = microtime(true);
            try {
                if (!$this->tick()) break;
            } catch (Throwable $e) {
                $this->onError($e);
            }
            $interval = $this->cfg['interval'] ?? 5;
            $until = $start + $interval;
            // Sleep until the next check, waking up regularly to notice a stop signal
            while ($this->running && microtime(true) < $until) usleep((int)min(250000, max(1000, ($until - microtime(true)) * 1e6)));
        }
        pfc_release_all($this->cfg);
        @unlink(PFC_STATUS);
        pfc_log('Stopped, fans handed back to the BIOS');
    }

    /** One check. Returns false when fan control was disabled. */
    public function tick(): bool {
        $now = time();
        clearstatcache();
        if (!$this->reload()) return false;

        $cfg = $this->cfg;
        $hw = pfc_scan_hwmon();
        $nv = pfc_uses_nvidia($cfg) ? pfc_nvidia(max(20, 3 * $cfg['interval'])) : ['available' => false, 'error' => null, 'gpus' => []];
        $sources = $this->withRetries(pfc_read_sources($cfg, $hw, $this->fans, null, $nv));
        $test = pfc_fantest_running();

        $warnings = $nv['error'] ? [$nv['error']] : [];
        $fansChanged = false;
        $groups = [];
        foreach ($cfg['groups'] as $g) {
            $groups[$g['id']] = $this->controlGroup($g, $sources, $hw, $test, $now, $warnings, $fansChanged);
        }
        if ($fansChanged) $this->saveFans();

        $this->lastWarnings = array_values(array_unique($warnings));
        $this->report($this->lastWarnings, $now);
        $used = $this->usedSources($sources);
        pfc_write_json(PFC_STATUS, [
            'time' => $now, 'pid' => getmypid(), 'interval' => $cfg['interval'],
            'groups' => $groups, 'sources' => $used, 'warnings' => array_values(array_unique($warnings)),
        ]);
        if ($now - $this->lastHistory >= PFC_HISTORY_EVERY) {
            $this->lastHistory = $now;
            $this->recordHistory($now, $used, $groups);
        }
        return true;
    }

    /** Sets every fan we control to full speed. Used after an error and when the service crashes. */
    public function failsafe(): void {
        $hw = pfc_scan_hwmon();
        $keys = $this->cfg ? array_keys(pfc_controlled_keys($this->cfg)) : [];
        foreach ($this->fans['pwm'] ?? [] as $key => $f) if (isset($f['orig_enable'])) $keys[] = $key;
        foreach (array_unique($keys) as $key) {
            $fan = $hw['pwm'][$key] ?? null;
            if (!$fan) continue;
            if ($fan['enable'] !== null && $fan['enable'] !== 1) @file_put_contents("{$fan['path']}_enable", '1');
            @file_put_contents($fan['path'], (string)PFC_FAILSAFE_PWM);
        }
    }

    /* ------------------------------------------------------------- config */

    /** (Re)loads the config and fan names when they changed. Returns false when disabled. */
    private function reload(): bool {
        // Compare content hashes, not mtimes: two saves within one second have the same mtime
        $hash = (string)@md5_file(PFC_CONFIG);
        if ($hash !== $this->cfgHash) {
            $new = pfc_load_config();
            if (!$new['enabled']) return false;
            $errors = pfc_validate_config($new);
            if ($errors) pfc_log('Invalid configuration, all fans run at full speed: ' . implode(' ', $errors));

            // Fans that are no longer in any group go back to the BIOS
            if ($this->cfg) {
                $gone = array_diff_key(pfc_controlled_keys($this->cfg), pfc_controlled_keys($new));
                if ($gone) {
                    $fans = pfc_load_fans();
                    $hw = pfc_scan_hwmon();
                    foreach (array_keys($gone) as $key) {
                        pfc_release_fan($fans, $key, $hw);
                        unset($this->written[$key], $this->conflicts[$key], $this->zeroSince[$key], $this->setAt[$key], $this->mismatchSince[$key]);
                    }
                    pfc_save_fans($fans);
                    $this->fansHash = null;
                }
            }
            $this->cfgHash = $hash;
            $this->cfg = $new;
            $this->cfgErrors = $errors;
            $this->windows = [];
        }
        $hash = (string)@md5_file(PFC_FANS);
        if ($hash !== $this->fansHash) {
            $this->fansHash = $hash;
            $this->fans = pfc_load_fans();
        }
        return true;
    }

    private function saveFans(): void {
        pfc_save_fans($this->fans);
        $this->fansHash = (string)@md5_file(PFC_FANS);
    }

    /* ------------------------------------------------------------ sources */

    /**
     * A single failed read (a sensor glitch, nvidia-smi failing once) uses the previous reading,
     * so it doesn't send the fans to full speed for the whole slow-down delay.
     */
    private function withRetries(array $sources): array {
        foreach ($sources as $id => $s) {
            if ($s['state'] !== 'error') {
                $this->failures[$id] = 0;
                $this->lastGood[$id] = $s;
                continue;
            }
            $this->failures[$id] = ($this->failures[$id] ?? 0) + 1;
            if ($this->failures[$id] <= self::SOURCE_RETRIES && isset($this->lastGood[$id])) {
                $sources[$id] = ['detail' => 'last reading, ' . $s['detail']] + $this->lastGood[$id];
            }
        }
        return $sources;
    }

    /** The sources used by a group, for status.json and the history. */
    private function usedSources(array $sources): array {
        $used = [];
        foreach ($this->cfg['groups'] as $g) foreach ($g['sources'] as $b) {
            $s = $sources[$b['source']] ?? null;
            $used[$b['source']] = $s
                ? ['temp' => $s['temp'], 'state' => $s['state'], 'detail' => $s['detail']]
                : ['temp' => null, 'state' => 'error', 'detail' => 'not available'];
        }
        return $used;
    }

    /* -------------------------------------------------------------- groups */

    private function controlGroup(array $g, array $sources, array $hw, ?array $test, int $now, array &$warnings, bool &$fansChanged): array {
        $gid = $g['id'];
        $demand = $this->cfgErrors
            ? ['pwm' => PFC_FAILSAFE_PWM, 'reason' => 'Invalid configuration, running at full speed', 'source' => null, 'demands' => []]
            : pfc_group_demand($g, $sources);

        // Keep the highest demand of the last <ramp_down> seconds
        $this->windows[$gid][] = [$now, $demand['pwm']];
        $this->windows[$gid] = array_values(array_filter($this->windows[$gid], fn($e) => $e[0] >= $now - $g['ramp_down']));
        $pwm = max(array_column($this->windows[$gid], 1));
        $slowDownIn = null;
        if ($pwm > $demand['pwm']) {
            $since = 0;
            foreach ($this->windows[$gid] as $e) if ($e[1] === $pwm) $since = $e[0];
            $slowDownIn = max(0, $since + $g['ramp_down'] - $now);
        }

        $count = 0;
        foreach ($g['fans'] as $key) {
            $name = $this->fanName($key, $hw);
            $fan = $hw['pwm'][$key] ?? null;
            if (!$fan) { $warnings[] = $this->missingFan($key, $name, $g['name'], $hw, $now); continue; }
            if (!$fan['writable']) { $warnings[] = "Fan \"$name\" can't be controlled (not writable)."; continue; }
            $count++;
            if ($test && ($test['key'] ?? '') === $key) {
                // Being tested from the web UI: leave it alone, and don't mistake the test for a conflict
                unset($this->written[$key], $this->zeroSince[$key], $this->setAt[$key], $this->mismatchSince[$key]);
                continue;
            }
            $this->checkConflict($fan, $name, $warnings);
            $error = pfc_drive_fan($this->fans, $fan, $pwm, $fansChanged);
            if ($error) $warnings[] = "Fan \"$name\": $error.";
            $this->written[$key] = [$pwm, 1];
            // Small adjustments don't restart the settle time, the mismatch check is tolerant enough
            if (!isset($this->setAt[$key]) || abs($this->setAt[$key][1] - $pwm) > 13) $this->setAt[$key] = [$now, $pwm];
            $rpmKey = pfc_confirmed_rpm($this->fans, $key);
            $rpm = $rpmKey ? ($hw['rpm'][$rpmKey]['rpm'] ?? null) : null;
            $this->checkStall($key, $name, $pwm, $rpmKey, $rpm, $now, $warnings);
            $this->checkMismatch($key, $name, $pwm, $rpmKey, $rpm, $now, $warnings);
        }
        if ($count) $this->hwCheck = null;

        // Log meaningful changes only (>= 5%, or entering/leaving full speed)
        $prev = $this->logged[$gid] ?? null;
        if ($this->cfg['log_changes'] && $count && ($prev === null || abs($prev - $pwm) >= 13 || ($pwm === 255) !== ($prev === 255))) {
            pfc_log("{$g['name']}: {$demand['reason']}, fans at " . pfc_pct($pwm) . "% ($pwm)");
            $this->logged[$gid] = $pwm;
        }

        return [
            'id' => $gid, 'name' => $g['name'], 'pwm' => $pwm, 'percent' => pfc_pct($pwm),
            'demand' => $demand['pwm'], 'reason' => $demand['reason'], 'source' => $demand['source'],
            'demands' => $demand['demands'], 'slow_down_in' => $slowDownIn, 'fans' => $count,
        ];
    }

    private function fanName(string $key, array $hw): string {
        $label = (string)($this->fans['pwm'][$key]['label'] ?? '');
        if ($label !== '') return $label;
        $fan = $hw['pwm'][$key] ?? null;
        return $fan ? "{$fan['chip']} PWM {$fan['index']}" : $key;
    }

    /**
     * Something else changed the speed or mode we set (another fan control, the BIOS/EC).
     * Some chips round the value they store, so small differences are ignored.
     */
    private function checkConflict(array $fan, string $name, array &$warnings): void {
        $key = $fan['key'];
        if (!isset($this->written[$key])) return;
        [$pwm, $enable] = $this->written[$key];
        $changed = ($fan['value'] !== null && abs($fan['value'] - $pwm) > 5) || ($fan['enable'] !== null && $fan['enable'] !== $enable);
        $this->conflicts[$key] = $changed ? ($this->conflicts[$key] ?? 0) + 1 : 0;
        if ($this->conflicts[$key] >= self::CONFLICT_TICKS) {
            $warnings[] = "Fan \"$name\": its speed keeps being changed by something else. Is another fan control running?";
        }
    }

    /**
     * A fan in a group that isn't there. Says why, as far as we can tell: the chip is missing (driver not
     * loaded, see the hardware check) or the chip is there but not this output (hardware changed).
     */
    private function missingFan(string $key, string $name, string $group, array $hw, int $now): string {
        $chip = strstr($key, '@', true) ?: strstr($key, '/', true);
        $msg = "Fan \"$name\" in \"$group\" was not found, it is not controlled.";
        foreach ($hw['pwm'] as $p) {
            if ($p['chip'] === $chip) return "$msg The $chip chip is there, but not this output: was the hardware or BIOS changed? Add the fan to the group again.";
        }
        if (!$this->hwCheck || $now - $this->hwCheck['time'] >= 60) $this->hwCheck = ['time' => $now] + pfc_hardware_check($hw);
        $hint = pfc_hardware_hint($this->hwCheck, true) ?? "Is the driver for the $chip chip loaded?";
        return "$msg $hint";
    }

    /** A fan that reports 0 RPM for a while although it should spin. Needs a confirmed RPM sensor. */
    private function checkStall(string $key, string $name, int $pwm, ?string $rpmKey, ?int $rpm, int $now, array &$warnings): void {
        // With a speed test we know where the fan starts, otherwise assume it spins from 30%
        $t = $this->speedTest($key, $rpmKey);
        $spinsFrom = $t ? ($t['start_pwm'] ?? $t['min_pwm'] ?? 0) : self::STALL_MIN_PWM;
        if ($rpm === null && $rpmKey && isset($this->zeroSince[$key])) return; // unreadable for a moment: keep counting
        if ($rpm !== 0 || $pwm < max(1, $spinsFrom)) {
            unset($this->zeroSince[$key]);
            return;
        }
        $this->zeroSince[$key] = $this->zeroSince[$key] ?? $now;
        if ($now - $this->zeroSince[$key] >= self::STALL_SECONDS) {
            $warnings[] = "Fan \"$name\" is not spinning (0 RPM). Check the fan and its cable, and whether it was moved to another header.";
        }
    }

    /** The finished speed test of a fan, if it was measured with its current RPM sensor. */
    private function speedTest(string $key, ?string $rpmKey): ?array {
        $t = $this->fans['pwm'][$key]['test'] ?? null;
        return is_array($t) && !empty($t['done']) && ($t['rpm_key'] ?? null) === $rpmKey ? $t : null;
    }

    /**
     * A fan that runs far faster or slower than its speed test measured at the same speed. Fan names and
     * groups belong to the header, so after moving fans to other headers the wrong fans would follow the
     * wrong temperatures without anything failing. Different fan models have quite different speeds, so
     * this catches most mix-ups. Needs a confirmed RPM sensor and a speed test.
     */
    private function checkMismatch(string $key, string $name, int $pwm, ?string $rpmKey, ?int $rpm, int $now, array &$warnings): void {
        $t = $this->speedTest($key, $rpmKey);
        $expected = $t ? pfc_expected_rpm($t['points'] ?? [], $pwm) : null;
        $settled = $now - ($this->setAt[$key][0] ?? $now) >= self::SETTLE_SECONDS;
        if ($rpm === null && isset($this->mismatchSince[$key])) return; // unreadable for a moment: keep counting
        if (!$settled || !$rpm || $expected === null || $expected < 300
            || ($rpm >= $expected * self::MISMATCH_LOW && $rpm <= $expected * self::MISMATCH_HIGH)) {
            unset($this->mismatchSince[$key]);
            return;
        }
        $this->mismatchSince[$key] = $this->mismatchSince[$key] ?? $now;
        if ($now - $this->mismatchSince[$key] >= self::MISMATCH_SECONDS) {
            // Keep the text stable (no live numbers), warnings are tracked by their text
            $warnings[] = "Fan \"$name\" runs much " . ($rpm > $expected ? 'faster' : 'slower') . ' than in its speed test. '
                . 'Was a fan moved to another header? Check with Identify and run the speed test again.';
        }
    }

    /* ----------------------------------------------------------- reporting */

    /**
     * Logs warnings and sends Unraid notifications, without flooding:
     *   - a warning has to last NOTIFY_AFTER seconds before an alert is sent, and is alerted once,
     *   - it only counts as resolved once it has been gone for RESOLVE_SECONDS, so a problem that comes
     *     and goes stays one problem (one alert, one "resolved" notice),
     *   - the same warning is not alerted again within REALERT_AFTER of its last alert,
     *   - the state is kept in PFC_ALERTS, so a restart of the service doesn't alert again (it is in
     *     /tmp, so after a reboot a problem that still exists is alerted once more).
     */
    private function report(array $warnings, int $now): void {
        $a = &$this->alerts;
        $before = $a;
        foreach (array_unique($warnings) as $w) {
            $e = $a['active'][$w] ?? null;
            if (!$e) {
                pfc_log("Warning: $w");
                $e = ['since' => $now, 'gone' => null, 'handled' => false, 'alerted' => false];
            }
            $e['gone'] = null;
            if (!$e['handled'] && $now - $e['since'] >= self::NOTIFY_AFTER) {
                $e['handled'] = true;
                if ($now - ($a['sent'][$w] ?? 0) >= self::REALERT_AFTER) {
                    pfc_notify('Fan problem', $w, 'alert');
                    $a['sent'][$w] = $now;
                    $e['alerted'] = true;
                }
            }
            $a['active'][$w] = $e;
        }
        foreach ($a['active'] as $w => $e) {
            if (in_array($w, $warnings, true)) continue;
            if ($e['gone'] === null) { $a['active'][$w]['gone'] = $now; continue; }
            if ($now - $e['gone'] < self::RESOLVE_SECONDS) continue;
            if ($e['alerted']) pfc_notify('Problem resolved', $w, 'normal');
            pfc_log("Resolved: $w");
            unset($a['active'][$w]);
        }
        $a['sent'] = array_filter($a['sent'], fn($t) => $now - $t < self::REALERT_AFTER);
        if ($a !== $before) pfc_write_json(PFC_ALERTS, $a);
    }

    private static function loadAlerts(): array {
        $a = pfc_read_json(PFC_ALERTS, []);
        $active = [];
        foreach (is_array($a['active'] ?? null) ? $a['active'] : [] as $w => $e) {
            if (!is_array($e) || !isset($e['since'])) continue;
            $active[(string)$w] = ['since' => (int)$e['since'], 'gone' => isset($e['gone']) ? (int)$e['gone'] : null,
                'handled' => !empty($e['handled']), 'alerted' => !empty($e['alerted'])];
        }
        $sent = array_map('intval', array_filter(is_array($a['sent'] ?? null) ? $a['sent'] : [], 'is_numeric'));
        return ['active' => $active, 'sent' => $sent];
    }

    private function recordHistory(int $now, array $sources, array $groups): void {
        $history = pfc_read_json(PFC_HISTORY, []);
        $history[] = [
            't' => $now,
            's' => array_map(fn($s) => $s['temp'], $sources),
            'g' => array_map(fn($g) => $g['pwm'], $groups),
        ];
        if (count($history) > PFC_HISTORY_MAX) $history = array_slice($history, -PFC_HISTORY_MAX);
        pfc_write_json(PFC_HISTORY, $history);
    }

    /**
     * An error in the loop: run at full speed, tell the user, try again next check. The warnings of the
     * last good check stay active, so they aren't reported as resolved while the error lasts.
     */
    private function onError(Throwable $e): void {
        $now = time();
        $msg = 'Internal error, fans run at full speed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        $this->written = [];
        try { $this->failsafe(); } catch (Throwable $ignored) {}
        $warnings = array_merge($this->lastWarnings, [$msg]);
        try { $this->report($warnings, $now); } catch (Throwable $ignored) {}
        pfc_write_json(PFC_STATUS, ['time' => $now, 'pid' => getmypid(), 'interval' => $this->cfg['interval'] ?? 5,
            'groups' => [], 'sources' => [], 'warnings' => $warnings]);
    }
}
