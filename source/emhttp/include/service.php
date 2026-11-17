<?php
/*
 * PWM Fan Control - the background service (scripts/daemon.php) and fan tests (scripts/fantest.php).
 */

function pfc_daemon_pid(): ?int {
    $pid = (int)trim((string)@file_get_contents(PFC_PIDFILE));
    return pfc_pid_alive($pid) ? $pid : null;
}

/**
 * Loads the configured drivers, then starts or stops the service and its watchdog cron job to match
 * the config. Runs at boot (install.sh) and whenever the settings are saved.
 */
function pfc_apply_service(array $cfg): void {
    pfc_load_drivers($cfg);
    if ($cfg['enabled']) {
        pfc_write_atomic(PFC_CRON, "# PWM Fan Control watchdog (generated, do not edit)\n* * * * * " . PFC_RC . " watchdog >/dev/null 2>&1\n\n", true);
    } else {
        @unlink(PFC_CRON);
    }
    exec(PFC_UPDATE_CRON . ' >/dev/null 2>&1');
    exec(PFC_RC . ($cfg['enabled'] ? ' start' : ' stop') . ' >/dev/null 2>&1');
}

/** The fan test (Identify or speed test) in progress, or null. */
function pfc_fantest_running(): ?array {
    $state = pfc_read_json(PFC_FANTEST);
    if (!is_array($state) || ($state['phase'] ?? '') === 'done') return null;
    // Just requested by the web UI, the script hasn't written its pid yet
    if (($state['phase'] ?? '') === 'starting') return time() - ($state['time'] ?? 0) < 10 ? $state : null;
    return pfc_pid_alive($state['pid'] ?? 0) ? $state : null;
}

/** Starts scripts/fantest.php in the background. $mode: identify|speed. Returns an error or null. */
function pfc_fantest_start(string $key, string $mode): ?string {
    if (pfc_fantest_running()) return 'Another fan test is running right now.';
    @unlink(PFC_STOP_FLAG);
    pfc_write_json(PFC_FANTEST, ['key' => $key, 'mode' => $mode, 'phase' => 'starting', 'pid' => 0, 'time' => time()]);
    exec('setsid nohup /usr/bin/php ' . escapeshellarg(PFC_PLUGIN . '/scripts/fantest.php') . ' ' . escapeshellarg($mode) . ' '
        . escapeshellarg($key) . ' >/dev/null 2>&1 < /dev/null &');
    return null;
}

/**
 * Why the fan $key can't be tested right now, or null. While a test runs, the service leaves the fan
 * alone, so a test must not start (and is stopped) when the fan's group needs it: one of its
 * temperatures is at or above "high", can't be read, or a parity check runs at full speed. Fans that
 * are in no group are not checked.
 */
function pfc_fan_needed(array $cfg, string $key, ?array $hw = null): ?string {
    $gid = pfc_controlled_keys($cfg)[$key] ?? null;
    $group = null;
    foreach ($cfg['groups'] as $g) if ($g['id'] === $gid) $group = $g;
    if (!$group || !$group['sources']) return null;
    $sources = pfc_read_sources($cfg, $hw ?? pfc_scan_hwmon(), pfc_load_fans());
    foreach ($group['sources'] as $b) {
        $s = $sources[$b['source']] ?? null;
        if (!$s || $s['state'] === 'error') return ($s ? pfc_source_name($s) : $b['source']) . " can't be read";
        if ($s['state'] === 'parity') return 'a parity check is running';
        if ($s['temp'] !== null && $s['temp'] >= $b['high']) {
            return pfc_source_name($s) . ' is at ' . pfc_fmt_temp($s['temp']) . " (high: {$b['high']}°C)";
        }
    }
    return null;
}
