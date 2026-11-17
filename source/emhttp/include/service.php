<?php
/*
 * PWM Fan Control - the background service (scripts/daemon.php) and fan tests (scripts/fantest.php).
 */

function pfc_daemon_pid(): ?int {
    $pid = (int)trim((string)@file_get_contents(PFC_PIDFILE));
    return pfc_pid_alive($pid) ? $pid : null;
}

/** Starts or stops the service and its watchdog cron job to match the config. */
function pfc_apply_service(array $cfg): void {
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
