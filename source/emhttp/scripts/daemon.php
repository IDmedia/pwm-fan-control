<?php
/*
 * PWM Fan Control - background service.
 * Started/stopped by rc.pwmfancontrol. Every <interval> seconds it reads the temperature sources,
 * works out the speed for every fan group and applies it.
 *
 * Speeding up happens right away. Slowing down only happens once the lower speed has been
 * wanted for <ramp_down> seconds (the group keeps the highest speed asked for in that window),
 * so short temperature dips don't make the fans pulse.
 */
require_once dirname(__DIR__) . '/include/common.php';

@mkdir(PFC_RUN, 0777, true);
$lock = fopen(PFC_RUN . '/daemon.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "PWM Fan Control is already running\n");
    exit(0);
}
file_put_contents(PFC_PIDFILE, (string)getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGHUP, SIG_IGN);
}

$cfg = null;
$cfg_mtime = null;
$cfg_errors = [];
$fans = [];
$fans_mtime = null;
$windows = [];       // group id => [[time, pwm], ...]
$logged = [];        // group id => last logged pwm
$last_history = 0;
$warned = [];

pfc_log('Started');

while ($running) {
    $tick = microtime(true);
    $now = time();
    clearstatcache();

    // (Re)load the configuration when it changes
    // Compare content hashes, not mtimes: two saves within one second have the same mtime
    $mtime = (string)@md5_file(PFC_CONFIG);
    if ($mtime !== $cfg_mtime) {
        $cfg_mtime = $mtime;
        $new = pfc_load_config();
        if (!$new['enabled']) break;
        $new_errors = pfc_validate_config($new);
        if ($new_errors) pfc_log('Invalid configuration, affected fans run at full speed: ' . implode(' ', $new_errors));

        // Fans that are no longer in any group go back to the BIOS
        if ($cfg) {
            $gone = array_diff_key(pfc_controlled_keys($cfg), pfc_controlled_keys($new));
            if ($gone) {
                $f = pfc_load_fans();
                $hw = pfc_scan_hwmon();
                foreach (array_keys($gone) as $key) pfc_release_fan($f, $key, $hw);
                pfc_save_fans($f);
                $fans_mtime = null;
            }
        }
        $cfg = $new;
        $cfg_errors = $new_errors;
        $windows = [];
    }
    $fmtime = (string)@md5_file(PFC_FANS);
    if ($fmtime !== $fans_mtime) { $fans_mtime = $fmtime; $fans = pfc_load_fans(); }

    $hw = pfc_scan_hwmon();
    $uses_nvidia = false;
    foreach ($cfg['groups'] as $g) foreach ($g['sources'] as $b) if (strpos($b['source'], 'nvidia:') === 0) $uses_nvidia = true;
    $nv = $uses_nvidia ? pfc_nvidia(max(20, 3 * $cfg['interval'])) : ['available' => false, 'error' => null, 'gpus' => []];
    $sources = pfc_read_sources($cfg, $hw, $fans, null, $nv);
    $identify = pfc_identify_running();

    $warnings = [];
    if ($nv['error']) $warnings[] = $nv['error'];
    $fans_changed = false;
    $status_groups = [];

    foreach ($cfg['groups'] as $g) {
        $gid = $g['id'];
        $demand = $cfg_errors
            ? ['pwm' => PFC_FAILSAFE_PWM, 'reason' => 'Invalid configuration, running at full speed', 'source' => null, 'demands' => []]
            : pfc_group_demand($g, $sources);

        // Keep the highest demand of the last <ramp_down> seconds
        $windows[$gid][] = [$now, $demand['pwm']];
        $windows[$gid] = array_values(array_filter($windows[$gid], fn($e) => $e[0] >= $now - $g['ramp_down']));
        $applied = max(array_column($windows[$gid], 1));
        $slow_down_in = null;
        if ($applied > $demand['pwm']) {
            $since = 0;
            foreach ($windows[$gid] as $e) if ($e[1] === $applied) $since = $e[0];
            $slow_down_in = max(0, $since + $g['ramp_down'] - $now);
        }

        $fan_count = 0;
        foreach ($g['fans'] as $key) {
            if (!isset($hw['pwm'][$key])) { $warnings[] = "Fan \"" . ($fans['pwm'][$key]['label'] ?? $key) . "\" in \"{$g['name']}\" was not found. Is its sensor driver loaded?"; continue; }
            $fan = $hw['pwm'][$key];
            if (!$fan['writable']) { $warnings[] = "Fan $key is not writable."; continue; }
            $fan_count++;
            if ($identify && ($identify['key'] ?? '') === $key) continue;

            if ($fan['enable'] !== null && $fan['enable'] !== 1) {
                // Remember the BIOS mode so it can be restored when the fan is released
                if (!isset($fans['pwm'][$key]['orig_enable'])) {
                    $fans['pwm'][$key]['orig_enable'] = (string)$fan['enable'];
                    $fans_changed = true;
                }
                @file_put_contents("{$fan['path']}_enable", '1');
            }
            if ($fan['value'] !== $applied) @file_put_contents($fan['path'], (string)$applied);
        }

        // Log meaningful changes only (>= 5%, or entering/leaving full speed)
        $prev = $logged[$gid] ?? null;
        if ($cfg['log_changes'] && $fan_count && ($prev === null || abs($prev - $applied) >= 13 || ($applied === 255) !== ($prev === 255))) {
            pfc_log("{$g['name']}: {$demand['reason']}, fans at " . round($applied * 100 / 255) . "% ($applied)");
            $logged[$gid] = $applied;
        }

        $status_groups[$gid] = [
            'id' => $gid, 'name' => $g['name'], 'pwm' => $applied, 'percent' => (int)round($applied * 100 / 255),
            'demand' => $demand['pwm'], 'reason' => $demand['reason'], 'source' => $demand['source'],
            'demands' => $demand['demands'], 'slow_down_in' => $slow_down_in, 'fans' => $fan_count,
        ];
    }
    if ($fans_changed) { pfc_save_fans($fans); $fans_mtime = (string)@md5_file(PFC_FANS); }

    foreach ($warnings as $w) {
        if (empty($warned[$w])) pfc_log("Warning: $w");
        $warned[$w] = $now;
    }
    foreach ($warned as $w => $t) if ($t < $now) unset($warned[$w]);

    $used = [];
    foreach ($cfg['groups'] as $g) foreach ($g['sources'] as $b) $used[$b['source']] = true;
    $status_sources = [];
    foreach (array_keys($used) as $id) {
        $s = $sources[$id] ?? null;
        $status_sources[$id] = $s ? ['temp' => $s['temp'], 'state' => $s['state'], 'detail' => $s['detail']] : ['temp' => null, 'state' => 'error', 'detail' => 'not available'];
    }
    pfc_write_json(PFC_STATUS, [
        'time' => $now, 'pid' => getmypid(), 'interval' => $cfg['interval'],
        'groups' => $status_groups, 'sources' => $status_sources, 'warnings' => array_values(array_unique($warnings)),
    ]);

    if ($now - $last_history >= PFC_HISTORY_EVERY) {
        $last_history = $now;
        $history = pfc_read_json(PFC_HISTORY, []);
        $history[] = [
            't' => $now,
            's' => array_map(fn($s) => $s['temp'], $status_sources),
            'g' => array_map(fn($g) => $g['pwm'], $status_groups),
        ];
        if (count($history) > PFC_HISTORY_MAX) $history = array_slice($history, -PFC_HISTORY_MAX);
        pfc_write_json(PFC_HISTORY, $history);
    }

    // Sleep until the next tick, waking up regularly to notice a stop signal
    $until = $tick + $cfg['interval'];
    while ($running && microtime(true) < $until) usleep((int)min(250000, max(1000, ($until - microtime(true)) * 1e6)));
}

pfc_release_all($cfg);
@unlink(PFC_STATUS);
if ((int)@file_get_contents(PFC_PIDFILE) === getmypid()) @unlink(PFC_PIDFILE);
pfc_log('Stopped, fans handed back to the BIOS');
