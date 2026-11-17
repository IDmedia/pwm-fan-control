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
 * It also watches for problems and reports them as warnings (status page, syslog, notifications):
 *   - a fan that stays at 0 RPM while it should spin (needs a confirmed RPM sensor),
 *   - a fan whose speed or mode is changed by something else (another fan control),
 *   - fans that can't be found or written.
 */
final class PfcController {
    private const SOURCE_RETRIES = 1;     // failed reads of a source tolerated before full speed
    private const STALL_SECONDS  = 20;    // 0 RPM for this long while it should spin = stalled
    private const STALL_MIN_PWM  = 77;    // 30%: below this, without a speed test, 0 RPM may be normal
    private const CONFLICT_TICKS = 2;     // checks in a row a fan has to be changed by someone else
    private const NOTIFY_AFTER   = 60;    // seconds a warning has to persist before a notification
    private const RESOLVE_TICKS  = 3;     // checks in a row a warning has to be gone to count as resolved

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
    private array $warnSince = [];    // warning => first seen
    private array $notified = [];     // warning => true once a notification was sent
    private array $warnMissing = [];  // warning => checks in a row it was gone
    private int $lastHistory = 0;
    private bool $failsafe = false;   // fans were set to full speed after an error in the loop

    public bool $running = true;

    /** Runs until stopped or disabled, then hands the fans back to the BIOS. */
    public function run(): void {
        pfc_log('Started');
        while ($this->running) {
            $start = microtime(true);
            try {
                if (!$this->tick()) break;
                $this->failsafe = false;
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

        $this->report($warnings, $now);
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
                        unset($this->written[$key], $this->conflicts[$key], $this->zeroSince[$key]);
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
            if (!$fan) { $warnings[] = "Fan \"$name\" in \"{$g['name']}\" was not found. Is its sensor driver loaded?"; continue; }
            if (!$fan['writable']) { $warnings[] = "Fan \"$name\" can't be controlled (not writable)."; continue; }
            $count++;
            if ($test && ($test['key'] ?? '') === $key) {
                // Being tested from the web UI: leave it alone, and don't mistake the test for a conflict
                unset($this->written[$key], $this->zeroSince[$key]);
                continue;
            }
            $this->checkConflict($fan, $name, $warnings);
            $error = pfc_drive_fan($this->fans, $fan, $pwm, $fansChanged);
            if ($error) $warnings[] = "Fan \"$name\": $error.";
            $this->written[$key] = [$pwm, 1];
            $this->checkStall($fan, $name, $pwm, $hw, $now, $warnings);
        }

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

    /** A fan that reports 0 RPM for a while although it should spin. Needs a confirmed RPM sensor. */
    private function checkStall(array $fan, string $name, int $pwm, array $hw, int $now, array &$warnings): void {
        $key = $fan['key'];
        $rpmKey = pfc_confirmed_rpm($this->fans, $key);
        $rpm = $rpmKey ? ($hw['rpm'][$rpmKey]['rpm'] ?? null) : null;
        // With a speed test we know where the fan starts, otherwise assume it spins from 30%
        $t = $this->fans['pwm'][$key]['test'] ?? null;
        $spinsFrom = is_array($t) && !empty($t['done']) ? ($t['start_pwm'] ?? $t['min_pwm'] ?? 0) : self::STALL_MIN_PWM;
        if ($rpm === null && $rpmKey && isset($this->zeroSince[$key])) return; // unreadable for a moment: keep counting
        if ($rpm !== 0 || $pwm < max(1, $spinsFrom)) {
            unset($this->zeroSince[$key]);
            return;
        }
        $this->zeroSince[$key] = $this->zeroSince[$key] ?? $now;
        if ($now - $this->zeroSince[$key] >= self::STALL_SECONDS) {
            $warnings[] = "Fan \"$name\" is not spinning (0 RPM). Check the fan and its cable.";
        }
    }

    /* ----------------------------------------------------------- reporting */

    /**
     * Logs new warnings, and sends a notification for warnings that persist. A warning only counts as
     * resolved once it is gone for RESOLVE_TICKS checks, so one good reading doesn't restart the clock.
     */
    private function report(array $warnings, int $now): void {
        $current = array_unique($warnings);
        foreach ($current as $w) {
            $this->warnMissing[$w] = 0;
            if (!isset($this->warnSince[$w])) {
                pfc_log("Warning: $w");
                $this->warnSince[$w] = $now;
            }
            if (empty($this->notified[$w]) && $now - $this->warnSince[$w] >= self::NOTIFY_AFTER) {
                $this->notified[$w] = true;
                if ($this->cfg['notify']) pfc_notify('Fan problem', $w, 'warning');
            }
        }
        foreach (array_diff(array_keys($this->warnSince), $current) as $w) {
            $this->warnMissing[$w] = ($this->warnMissing[$w] ?? 0) + 1;
            if ($this->warnMissing[$w] < self::RESOLVE_TICKS) continue;
            if (!empty($this->notified[$w]) && $this->cfg['notify']) pfc_notify('Fan problem resolved', $w, 'normal');
            pfc_log("Resolved: $w");
            unset($this->warnSince[$w], $this->notified[$w], $this->warnMissing[$w]);
        }
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

    /** An error in the loop: run at full speed, tell the user, try again next check. */
    private function onError(Throwable $e): void {
        $msg = 'Internal error, fans run at full speed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        if (!$this->failsafe) pfc_log($msg);
        $this->failsafe = true;
        $this->written = [];
        try { $this->failsafe(); } catch (Throwable $ignored) {}
        pfc_write_json(PFC_STATUS, ['time' => time(), 'pid' => getmypid(), 'interval' => $this->cfg['interval'] ?? 5,
            'groups' => [], 'sources' => [], 'warnings' => [$msg]]);
    }
}
