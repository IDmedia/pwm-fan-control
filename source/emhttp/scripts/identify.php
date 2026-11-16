<?php
/*
 * PWM Fan Control - identify a single PWM output.
 * Usage: php identify.php <fan key>
 *
 * Slows the fan down, spins it up to full speed and restores the original state,
 * while sampling every RPM sensor on the same chip once per second. Progress is
 * written to PFC_IDENTIFY so the web UI can show it live. If exactly one RPM sensor
 * reacts, it is stored as the RPM sensor of this fan.
 */
require_once dirname(__DIR__) . '/include/common.php';

$key = $argv[1] ?? '';
$cfg = pfc_load_config();
$hw = pfc_scan_hwmon();
if (!isset($hw['pwm'][$key])) {
    pfc_write_json(PFC_IDENTIFY, ['key' => $key, 'phase' => 'done', 'error' => 'Fan not found', 'time' => time()]);
    exit(1);
}

$fan = $hw['pwm'][$key];
$path = $fan['path'];
$chip_prefix = substr($key, 0, strrpos($key, '/') + 1);
$sensors = array_filter($hw['rpm'], fn($k) => strpos($k, $chip_prefix) === 0, ARRAY_FILTER_USE_KEY);

$low_pwm = max(0, min(255, (int)$cfg["identify"]["low_pwm"]));
$seconds = max(3, min(30, (int)$cfg["identify"]["seconds"]));

$state = [
    'key'      => $key,
    'pid'      => getmypid(),
    'phase'    => 'low',
    'time'     => time(),
    'seconds'  => $seconds,
    'low_pwm'  => $low_pwm,
    'samples'  => [],   // [{phase, t, rpm: {sensor key: rpm}}]
    'reacted'  => [],
    'paired'   => null,
    'error'    => null,
];

// Always restore the fan, whatever happens
$orig_value = $fan['value'] ?? 255;
$orig_enable = $fan['enable'];
$restored = false;
$restore = function () use ($path, $orig_value, $orig_enable, &$restored) {
    if ($restored) return;
    $restored = true;
    @file_put_contents($path, (string)$orig_value);
    if ($orig_enable !== null) @file_put_contents("{$path}_enable", (string)$orig_enable);
};
register_shutdown_function($restore);
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) pcntl_signal($sig, function () { exit(1); });
}

function pfc_sample(array $sensors): array {
    $out = [];
    foreach ($sensors as $k => $s) $out[$k] = pfc_read_int($s['path']);
    return $out;
}

$state['before'] = pfc_sample($sensors);
pfc_write_json(PFC_IDENTIFY, $state);

if ($orig_enable !== null && $orig_enable !== 1) @file_put_contents("{$path}_enable", '1');

$stopped = false;
foreach (['low' => $low_pwm, 'high' => 255] as $phase => $value) {
    $state['phase'] = $phase;
    @file_put_contents($path, (string)$value);
    for ($i = 1; $i <= $seconds; $i++) {
        sleep(1);
        $state['samples'][] = ['phase' => $phase, 't' => $i, 'rpm' => pfc_sample($sensors)];
        pfc_write_json(PFC_IDENTIFY, $state);
        if (is_file(PFC_STOP_FLAG)) { $stopped = true; break 2; }
    }
}
$restore();
@unlink(PFC_STOP_FLAG);

if ($stopped) {
    $state['error'] = 'Stopped';
} else {
    // A sensor "reacted" if its full-speed RPM is clearly above its slow RPM
    $last = [];
    foreach ($state['samples'] as $s) $last[$s['phase']] = $s['rpm'];
    $scores = [];
    foreach ($sensors as $k => $_) {
        $low = (int)($last['low'][$k] ?? 0);
        $high = (int)($last['high'][$k] ?? 0);
        if ($high > 0 && $high * 10 > $low * 13 && $high - $low > 150) $scores[$k] = $high - $low;
    }
    arsort($scores);
    $state['reacted'] = array_keys($scores);

    if (count($scores) === 1) {
        $fans = pfc_load_fans();
        $fans['pwm'][$key]['rpm'] = $state['reacted'][0];
        pfc_save_fans($fans);
        $state['paired'] = $state['reacted'][0];
    }
}

$state['phase'] = 'done';
$state['time'] = time();
pfc_write_json(PFC_IDENTIFY, $state);
