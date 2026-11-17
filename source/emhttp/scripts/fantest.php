<?php
/*
 * PWM Fan Control - tests a single PWM output, started from the web UI.
 * Usage: php fantest.php identify|speed <fan key>
 *
 *   identify  Slows the fan down until it stops slowing, then runs it at full speed, so you can see and
 *             hear which fan it is.
 *   speed     Steps the fan down from 100% and records the RPM at every step, until it stops (or 0% is
 *             reached). Then finds the speed it needs to start again, trying from the lowest speed it
 *             kept running at upwards. The fan stands still only for a few seconds at a time.
 *
 * Every RPM sensor on the same chip is sampled once per second. Progress is written to PFC_FANTEST so
 * the web UI can show it live. If exactly one sensor reacts, it is paired with the output.
 *
 * While a test runs, the service leaves this fan alone. So the test is stopped right away when the fan's
 * group needs it (a temperature reaches "high", can't be read, or a parity check starts), see
 * pfc_fan_needed(). The output is always restored to its previous speed and mode afterwards.
 */
require_once dirname(__DIR__) . '/include/common.php';

const PFC_SPEED_DOWN   = [255, 204, 153, 102, 77, 51, 38, 26, 13, 0]; // 100% .. 0%
const PFC_SPEED_UP     = [26, 38, 51, 77, 102, 153, 204, 255];        // start tries, from the lowest running speed up
const PFC_SPEED_STEP   = 5;   // seconds per step going down: time for the RPM to settle
const PFC_START_STEP   = 3;   // seconds per start try: a fan that starts does so within 1-2 seconds
const PFC_FOLLOW_PWM   = 102; // from this step on, sensors that follow the speed changes are watched for a stop
const PFC_NEEDED_EVERY = 2;   // seconds between checks whether the fan's group needs the fan
const PFC_SETTLE_MAX   = 30;  // Identify: longest wait for a fan to finish slowing down

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
$total = $mode === 'identify' ? 2 * $seconds + 5 : count(PFC_SPEED_DOWN) * PFC_SPEED_STEP + 2;

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

$needed = null; // why the test was stopped because the fan was needed

/**
 * Runs the fan at $pwm for up to $secs seconds, sampling once per second. Ends early when $until($rpm)
 * returns true. Returns the last RPM sample, or null when the test was stopped (by the user, or because
 * the fan's group needs the fan).
 */
$hold = function (string $phase, int $pwm, int $secs, ?callable $until = null)
        use (&$state, &$total, &$needed, $path, $sample, $cfg, $key): ?array {
    $state['phase'] = $phase;
    $state['pwm'] = $pwm;
    @file_put_contents($path, (string)$pwm);
    $rpm = [];
    for ($i = 1; $i <= $secs; $i++) {
        sleep(1);
        $rpm = $sample();
        $state['samples'][] = ['phase' => $phase, 'pwm' => $pwm, 'rpm' => $rpm];
        $state['progress'] = max($state['progress'], min(99, (int)round(count($state['samples']) * 100 / max(1, $total))));
        pfc_write_json(PFC_FANTEST, $state);
        if (is_file(PFC_STOP_FLAG)) return null;
        if (count($state['samples']) % PFC_NEEDED_EVERY === 0 && ($needed = pfc_fan_needed($cfg, $key)) !== null) return null;
        if ($until && $until($rpm)) break;
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
    // A fan takes longer to slow down than to speed up, so the slow step lasts until the RPM has
    // stopped falling (at least $seconds, at most PFC_SETTLE_MAX), and the change is easy to see.
    // Without a paired sensor: the sensors that dropped clearly since the start. Other fans on the chip
    // can be changing speed at the same time (their group), they must not keep the step going.
    $history = [];
    $settled = function (array $rpm) use (&$history, &$state, $paired, $sensors, $seconds): bool {
        $history[] = $rpm;
        $n = count($history);
        if ($n < $seconds || $n < 4) return false;
        $watch = $paired ? [$paired] : array_keys(array_filter($sensors, fn($s, $k) =>
            (int)($rpm[$k] ?? 0) < 0.85 * (int)($state['before'][$k] ?? 0), ARRAY_FILTER_USE_BOTH));
        foreach ($watch as $k) {
            $now = (int)($rpm[$k] ?? 0);
            $before = (int)($history[$n - 4][$k] ?? 0);
            if ($now < $before * 0.97) return false; // still slowing down
        }
        return true;
    };
    $low = $hold('low', $low_pwm, max($seconds, PFC_SETTLE_MAX), $settled);
    $total = count($state['samples']) + $seconds;
    $high = $low === null ? null : $hold('high', 255, $seconds);
    if ($high === null) $stopped = true;
    else $state['reacted'] = $reacting($low, $high);
} else {
    // Down: record the RPM at every step, and stop going down once the fan stands still. Which sensor
    // belongs to the fan is known once the speed has dropped a bit: the ones that follow the steps.
    $down = [];
    $watch = $paired ? [$paired] : [];
    $isStopped = function (array $rpm) use (&$watch): bool {
        foreach ($watch as $k) if (($rpm[$k] ?? null) === 0) return true;
        return false;
    };
    foreach (PFC_SPEED_DOWN as $pwm) {
        $check = $watch && $pwm !== 255 ? $isStopped : null; // at 100% a fan may still be spinning up from standstill
        $rpm = $hold('down', $pwm, PFC_SPEED_STEP + ($pwm === 255 ? 2 : 0), $check); // the first step may spin up from slow
        if ($rpm === null) { $stopped = true; break; }
        $down[$pwm] = $rpm;
        if ($check && $isStopped($rpm)) break;
        if (!$paired && $pwm === PFC_FOLLOW_PWM) {
            $watch = array_keys(array_filter($rpm, fn($r, $k) => $r !== null && (int)($down[255][$k] ?? 0) > 0 && $r < 0.8 * $down[255][$k], ARRAY_FILTER_USE_BOTH));
        }
    }
    if (!$stopped) {
        $last = array_key_last($down);
        $state['reacted'] = $reacting($down[$last], $down[255]);
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
            // Up: the fan stopped, find the speed it needs to start again. It can't start below the lowest
            // speed it kept running at, so the tries begin there.
            if ($min_pwm !== null && ($down[$last][$sensor] ?? null) === 0) {
                $tries = array_values(array_filter(PFC_SPEED_UP, fn($p) => $p >= $min_pwm));
                $total = count($state['samples']) + count($tries) * PFC_START_STEP;
                $started = fn($rpm) => (int)($rpm[$sensor] ?? 0) > 0;
                foreach ($tries as $pwm) {
                    $rpm = $hold('up', $pwm, PFC_START_STEP, $started);
                    if ($rpm === null) { $stopped = true; break; }
                    if ($started($rpm)) { $start_pwm = $pwm; break; }
                }
                if (!$stopped && $start_pwm === null) $state['error'] = 'The fan did not start again, not even at 100%. Check the fan.';
            }
            if (!$stopped && $min_pwm !== null && !$state['error']) {
                $state['result'] = [
                    'done' => true, 'time' => time(), 'rpm_key' => $sensor, 'points' => $points,
                    'max_rpm' => $down[255][$sensor] ?? null,
                    'min_pwm' => $min_pwm,        // lowest speed it keeps running at
                    'start_pwm' => $start_pwm,    // speed it needs to start from standstill (null: never stops)
                ];
            }
        }
    }
}
$restore();
@unlink(PFC_STOP_FLAG);

if ($stopped) {
    $state['error'] = $needed !== null ? "Stopped early, the fan is needed: $needed. Try again when it is cooler." : 'Stopped';
    if ($needed !== null) pfc_log("Fan test of $key stopped early, the fan is needed: $needed");
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
