<?php
/*
 * PWM Fan Control - tests a single PWM output, started from the web UI.
 * Usage: php fantest.php identify|speed <fan key>
 *
 *   identify  Slows the fan down, then runs it at full speed, so you can see and hear which fan it is.
 *   speed     Steps the fan from 100% down to 0% and back up until it starts again, and records the
 *             RPM at every step: the lowest speed it keeps running at and the speed it needs to start.
 *
 * Every RPM sensor on the same chip is sampled once per second. Progress is written to PFC_FANTEST so
 * the web UI can show it live. If exactly one sensor reacts, it is paired with the output. The output
 * is always restored to its previous speed and mode afterwards, also when the test is stopped.
 * While a test runs, the service leaves this fan alone.
 */
require_once dirname(__DIR__) . '/include/common.php';

const PFC_SPEED_DOWN = [255, 204, 153, 102, 77, 51, 38, 26, 13, 0]; // 100% .. 0%
const PFC_SPEED_UP   = [13, 26, 38, 51, 77, 102, 153];              // until the fan starts again
const PFC_SPEED_STEP = 5;                                           // seconds per step

$mode = $argv[1] ?? '';
$key = $argv[2] ?? '';
$cfg = pfc_load_config();
$hw = pfc_scan_hwmon();
if (!in_array($mode, ['identify', 'speed'], true) || !isset($hw['pwm'][$key])) {
    pfc_write_json(PFC_FANTEST, ['key' => $key, 'mode' => $mode, 'phase' => 'done', 'error' => 'Fan not found', 'time' => time()]);
    exit(1);
}

$fan = $hw['pwm'][$key];
$path = $fan['path'];
$chip_prefix = substr($key, 0, strrpos($key, '/') + 1);
$sensors = array_filter($hw['rpm'], fn($k) => strpos($k, $chip_prefix) === 0, ARRAY_FILTER_USE_KEY);
$fans = pfc_load_fans();
$paired = pfc_confirmed_rpm($fans, $key);

$low_pwm = max(0, min(255, (int)$cfg['identify']['low_pwm']));
$seconds = max(3, min(30, (int)$cfg['identify']['seconds']));
$total = $mode === 'identify' ? 2 * $seconds : (count(PFC_SPEED_DOWN) + 2) * PFC_SPEED_STEP;

$state = [
    'key'      => $key,
    'mode'     => $mode,
    'pid'      => getmypid(),
    'phase'    => $mode === 'identify' ? 'low' : 'down',
    'pwm'      => null,      // speed being tested right now
    'time'     => time(),
    'progress' => 0,         // percent, an estimate for the speed test
    'low_pwm'  => $low_pwm,
    'before'   => [],        // RPM of every sensor before the test
    'samples'  => [],        // [{phase, pwm, rpm: {sensor key: rpm}}], once per second
    'reacted'  => [],        // sensors that followed the speed changes
    'paired'   => null,      // sensor paired by this test
    'result'   => null,      // speed test result, also stored in fans.json
    'error'    => null,
];

// Always restore the fan, whatever happens
$orig_value = $fan['value'] ?? PFC_FAILSAFE_PWM;
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

$sample = function () use ($sensors): array {
    $out = [];
    foreach ($sensors as $k => $s) $out[$k] = pfc_read_int($s['path']);
    return $out;
};

/** Runs the fan at $pwm for $secs seconds. Returns the last RPM sample, or null when stopped. */
$hold = function (string $phase, int $pwm, int $secs) use (&$state, $path, $sample, &$total): ?array {
    $state['phase'] = $phase;
    $state['pwm'] = $pwm;
    @file_put_contents($path, (string)$pwm);
    $rpm = [];
    for ($i = 0; $i < $secs; $i++) {
        sleep(1);
        $rpm = $sample();
        $state['samples'][] = ['phase' => $phase, 'pwm' => $pwm, 'rpm' => $rpm];
        $state['progress'] = max($state['progress'], min(99, (int)round(count($state['samples']) * 100 / $total)));
        pfc_write_json(PFC_FANTEST, $state);
        if (is_file(PFC_STOP_FLAG)) return null;
    }
    return $rpm;
};

/** Sensors whose RPM at $high is clearly above their RPM at $low, best first. */
$reacting = function (array $low, array $high) use ($sensors): array {
    $scores = [];
    foreach ($sensors as $k => $_) {
        $l = (int)($low[$k] ?? 0);
        $h = (int)($high[$k] ?? 0);
        if ($h > 0 && $h * 10 > $l * 13 && $h - $l > 150) $scores[$k] = $h - $l;
    }
    arsort($scores);
    return array_keys($scores);
};

$state['before'] = $sample();
pfc_write_json(PFC_FANTEST, $state);
if ($orig_enable !== null && $orig_enable !== 1) @file_put_contents("{$path}_enable", '1');

$stopped = false;
if ($mode === 'identify') {
    $low = $hold('low', $low_pwm, $seconds);
    $high = $low === null ? null : $hold('high', 255, $seconds);
    if ($high === null) $stopped = true;
    else $state['reacted'] = $reacting($low, $high);
} else {
    // Down: record the RPM at every step
    $down = [];
    foreach (PFC_SPEED_DOWN as $pwm) {
        $rpm = $hold('down', $pwm, PFC_SPEED_STEP + ($pwm === 255 ? 2 : 0)); // the first step may spin up from slow
        if ($rpm === null) { $stopped = true; break; }
        $down[$pwm] = $rpm;
    }
    if (!$stopped) {
        $state['reacted'] = $reacting($down[0], $down[255]);
        $sensor = $paired ?? (count($state['reacted']) === 1 ? $state['reacted'][0] : null);
        if ($sensor === null) {
            $state['error'] = count($state['reacted']) > 1
                ? 'Several RPM sensors reacted. Pick the right one in the RPM sensor column, then run the test again.'
                : 'No RPM sensor reacted, so the speeds could not be measured.';
        } else {
            $points = [];
            foreach ($down as $pwm => $rpm) $points[] = [$pwm, $rpm[$sensor] ?? null];
            $running = array_filter($points, fn($p) => (int)$p[1] > 0);
            $min_pwm = $running ? min(array_column($running, 0)) : null;
            $start_pwm = null;
            if ($min_pwm === null) $state['error'] = 'The fan did not spin at any speed. Check the fan, its cable and the RPM sensor pairing.';
            // Up: if the fan stopped, find the speed it needs to start again
            if (($down[0][$sensor] ?? 0) === 0 && $min_pwm !== null) {
                $total = count($state['samples']) + count(PFC_SPEED_UP) * PFC_SPEED_STEP;
                $start_pwm = 255;
                foreach (PFC_SPEED_UP as $pwm) {
                    $rpm = $hold('up', $pwm, PFC_SPEED_STEP);
                    if ($rpm === null) { $stopped = true; break; }
                    if ((int)($rpm[$sensor] ?? 0) > 0) { $start_pwm = $pwm; break; }
                }
            }
            if (!$stopped && $min_pwm !== null) {
                $state['result'] = [
                    'done' => true, 'time' => time(), 'rpm_key' => $sensor, 'points' => $points,
                    'max_rpm' => $down[255][$sensor] ?? null,
                    'min_pwm' => $min_pwm ?? 0,   // lowest speed it keeps running at
                    'start_pwm' => $start_pwm,    // speed it needs to start from standstill (null: never stops)
                ];
            }
        }
    }
}
$restore();
@unlink(PFC_STOP_FLAG);

if ($stopped) {
    $state['error'] = 'Stopped';
} else {
    // Store the pairing (when exactly one sensor reacted) and the speed test result
    $fans = pfc_load_fans();
    if (!$paired && count($state['reacted']) === 1) {
        $fans['pwm'][$key]['rpm'] = $state['paired'] = $state['reacted'][0];
    }
    if ($state['result']) $fans['pwm'][$key]['test'] = $state['result'];
    pfc_save_fans($fans);
}

$state['phase'] = 'done';
$state['progress'] = 100;
$state['time'] = time();
pfc_write_json(PFC_FANTEST, $state);
