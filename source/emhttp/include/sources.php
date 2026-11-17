<?php
/*
 * PWM Fan Control - temperature sources and fan curves.
 *
 * A source, as returned by pfc_read_sources():
 *   ['id', 'type' => disk|nvidia|hwmon, 'name' (default name), 'label' (user name), 'temp' => ?float,
 *    'state' => ok|idle|parity|error, 'detail' => string]
 */

/**
 * The disk source a disk belongs to: "array" (parity and data disks), "pool:<name>" (one per pool),
 * "unassigned" or "flash". Pool devices are named after their pool ("cache", "cache2", ...), ZFS
 * subpools as "<pool>~<subpool>", so the pool name is the part before "~" without trailing digits.
 */
function pfc_disk_source_id(string $type, string $name): ?string {
    switch ($type) {
        case 'Parity': case 'Data': return 'array';
        case 'Cache': return 'pool:' . preg_replace('/\d+$/', '', explode('~', $name)[0]);
        case 'Unassigned': return 'unassigned';
        case 'Flash': case 'Boot': return 'flash';
    }
    return null;
}

function pfc_is_disk_source(string $id): bool {
    return in_array($id, ['array', 'unassigned', 'flash'], true) || strpos($id, 'pool:') === 0;
}

/** Default name of a disk source. */
function pfc_disk_source_name(string $id): string {
    if (strpos($id, 'pool:') === 0) return 'Pool: ' . substr($id, 5);
    return ['array' => 'Array', 'unassigned' => 'Unassigned disks', 'flash' => 'Flash'][$id] ?? $id;
}

/** Unraid's disks (array, pools and unassigned devices), with the disk source each one belongs to. */
function pfc_read_disks(array $cfg): array {
    $exclude = $cfg['array']['exclude'];
    $disks = [];

    $files = [[PFC_EMHTTP . '/disks.ini', null]];
    foreach ([PFC_EMHTTP . '/devs.ini', '/usr/local/emhttp/state/devs.ini'] as $devs) {
        if (is_file($devs)) { $files[] = [$devs, 'Unassigned']; break; }
    }
    foreach ($files as [$file, $force_type]) {
        $ini = is_file($file) ? @parse_ini_file($file, true) : false;
        if (!is_array($ini)) continue;
        foreach ($ini as $section => $d) {
            if (!is_array($d)) continue;
            $type = $force_type ?? (string)($d['type'] ?? '');
            $temp = trim((string)($d['temp'] ?? ''), '"');
            $disk = [
                'name' => (string)$section, 'type' => $type, 'device' => (string)($d['id'] ?? ''),
                'source' => pfc_disk_source_id($type, (string)$section),
                'temp' => preg_match('/^\d+$/', $temp) ? (int)$temp : null,
                'spundown' => ($d['spundown'] ?? '0') == '1',
                'included' => true, 'reason' => '',
            ];
            if ($disk['device'] === '') { $disk['included'] = false; $disk['reason'] = 'Not assigned'; }
            elseif ($disk['source'] === null) { $disk['included'] = false; $disk['reason'] = "$type disks are not used"; }
            elseif (in_array($disk['name'], $exclude, true)) { $disk['included'] = false; $disk['reason'] = 'Excluded'; }
            $disks[] = $disk;
        }
    }
    return $disks;
}

function pfc_parity_running(): bool {
    $var = @parse_ini_file(PFC_EMHTTP . '/var.ini');
    return is_array($var) && (int)trim((string)($var['mdResync'] ?? '0'), '"') > 0;
}

/** Whether the array is started. Unknown (no var.ini) counts as started. */
function pfc_array_started(): bool {
    $var = @parse_ini_file(PFC_EMHTTP . '/var.ini');
    if (!is_array($var) || !isset($var['mdState'])) return true;
    return trim((string)$var['mdState'], '"') === 'STARTED';
}

/**
 * Nvidia GPUs via nvidia-smi. Returns ['available' => bool, 'error' => ?string, 'gpus' => [...]].
 *
 * nvidia-smi always runs in the background and is never waited on: when a GPU or the driver
 * crashes, nvidia-smi can hang so hard that even "timeout"/SIGKILL can't end it, and a caller
 * waiting on it would freeze fan control. Instead the latest finished result is used. When it
 * is older than $max_age seconds, every GPU is reported without a temperature, which makes the
 * groups using them run at full speed. A new query only starts when the previous one finished.
 */
function pfc_nvidia(int $max_age = 20): array {
    if (!is_executable(PFC_NVIDIA_SMI)) return ['available' => false, 'error' => null, 'gpus' => []];
    @mkdir(PFC_RUN, 0777, true);
    $out = PFC_RUN . '/nvidia.out';
    $rcf = PFC_RUN . '/nvidia.rc';
    $pidf = PFC_RUN . '/nvidia.pid';

    $lock = fopen(PFC_RUN . '/nvidia.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);

    // Picks up the result of a finished run (nvidia.rc appears when it is done)
    $collect = function () use ($out, $rcf, $pidf): bool {
        $claim = $rcf . '.' . getmypid();
        if (!@rename($rcf, $claim)) return false;
        $rc = (int)trim((string)@file_get_contents($claim));
        $lines = array_filter(array_map('trim', explode("\n", (string)@file_get_contents($out))));
        $result = ['time' => (int)@filemtime($claim), 'error' => null, 'gpus' => []];
        if ($rc !== 0) {
            $result['error'] = $rc === 124 || $rc === 137 ? 'nvidia-smi timed out' : 'nvidia-smi failed: ' . implode(' ', array_slice($lines, 0, 2));
        } else {
            foreach ($lines as $line) {
                $p = array_map('trim', str_getcsv($line));
                if (count($p) < 4 || strpos($p[1], 'GPU-') !== 0) continue;
                $result['gpus'][] = ['index' => (int)$p[0], 'uuid' => $p[1], 'name' => $p[2], 'temp' => is_numeric($p[3]) ? (float)$p[3] : null];
            }
        }
        // Keep the GPU list from the last good result, so failing GPUs stay visible (without a temperature)
        if ($result['error']) {
            $prev = pfc_read_json(PFC_NV_CACHE, []);
            $result['gpus'] = array_map(fn($g) => ['temp' => null] + $g, $prev['gpus'] ?? []);
        }
        pfc_write_json(PFC_NV_CACHE, $result);
        @unlink($claim);
        @unlink($pidf);
        return true;
    };
    $launch = function () use ($out, $rcf, $pidf): void {
        $cmd = '(timeout -k 2 5 ' . escapeshellarg(PFC_NVIDIA_SMI) . ' --query-gpu=index,uuid,name,temperature.gpu --format=csv,noheader,nounits'
             . ' > ' . escapeshellarg($out) . ' 2>&1; echo $? > ' . escapeshellarg("$rcf.tmp") . '; mv ' . escapeshellarg("$rcf.tmp") . ' ' . escapeshellarg($rcf) . ')'
             . ' > /dev/null 2>&1 < /dev/null & echo $!';
        file_put_contents($pidf, trim((string)shell_exec($cmd)));
    };

    $collect();
    $cache = pfc_read_json(PFC_NV_CACHE);
    $busy = pfc_pid_alive(trim((string)@file_get_contents($pidf))) || is_file($rcf);
    $fresh = is_array($cache) && time() - ($cache['time'] ?? 0) < 2;
    if (!$busy && !$fresh) $launch();

    // Very first call: wait briefly for a result, instead of starting with "no data"
    if (!is_array($cache)) {
        for ($i = 0; $i < 60 && !$collect(); $i++) usleep(100000);
        $cache = pfc_read_json(PFC_NV_CACHE);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

    $result = ['available' => true, 'error' => $cache['error'] ?? null, 'gpus' => $cache['gpus'] ?? []];
    if (!is_array($cache)) {
        $result['error'] = 'nvidia-smi is not responding';
    } elseif (time() - ($cache['time'] ?? 0) > $max_age) {
        $result['error'] = "nvidia-smi has not answered for more than $max_age seconds";
        $result['gpus'] = array_map(fn($g) => ['temp' => null] + $g, $result['gpus']);
    }
    return $result;
}

/** Reads every available temperature source. Pass $disks/$nv when already read. */
function pfc_read_sources(array $cfg, array $hw, array $fans, ?array $disks = null, ?array $nv = null): array {
    $sources = [];
    $label = fn($id) => (string)($fans['temp'][$id]['label'] ?? '');

    // Disks: one source for the array, one per pool, unassigned devices and the flash drive. Each one
    // is the hottest of its disks that are used and spun up.
    $disks = $disks ?? pfc_read_disks($cfg);
    $bySource = [];
    foreach ($disks as $d) if ($d['device'] !== '' && $d['source'] !== null) $bySource[$d['source']][] = $d;
    uksort($bySource, fn($a, $b) => [pfc_disk_order($a), $a] <=> [pfc_disk_order($b), $b]);
    $parity = null;
    $started = null;
    foreach ($bySource as $id => $list) {
        $included = array_filter($list, fn($d) => $d['included']);
        $active = array_filter($included, fn($d) => !$d['spundown']);
        $hot = null;
        foreach ($active as $d) if ($d['temp'] !== null && ($hot === null || $d['temp'] > $hot['temp'])) $hot = $d;
        // Most flash drives report no temperature: only list it when it does, or when a group uses it
        if ($id === 'flash' && !$hot && !in_array('flash', array_column(array_merge([], ...array_column($cfg['groups'], 'sources')), 'source'), true)) continue;
        $src = ['id' => $id, 'type' => 'disk', 'name' => pfc_disk_source_name($id), 'label' => $label($id), 'temp' => $hot['temp'] ?? null, 'state' => 'ok', 'detail' => $hot ? "hottest is {$hot['name']}" : ''];
        $inArray = $id === 'array' || strpos($id, 'pool:') === 0; // only there while the array is started
        if (!$included) { $src['state'] = 'error'; $src['detail'] = 'all its disks are unticked'; }
        elseif ($id === 'array' && $cfg['array']['parity_full_speed'] && ($parity ??= pfc_parity_running())) { $src['state'] = 'parity'; $src['detail'] = 'parity check/rebuild running'; }
        elseif (!$active) { $src['state'] = 'idle'; $src['detail'] = 'all disks spun down'; }
        // A stopped array (e.g. an encrypted array waiting for its passphrase) has no temperatures: like spun down
        elseif (!$hot && $inArray && !($started ??= pfc_array_started())) { $src['state'] = 'idle'; $src['detail'] = 'array is stopped'; }
        elseif (!$hot) { $src['state'] = 'error'; $src['detail'] = 'no disk temperature available'; }
        $sources[$id] = $src;
    }

    // Nvidia GPUs
    $nv = $nv ?? pfc_nvidia();
    foreach ($nv['gpus'] as $g) {
        $id = 'nvidia:' . $g['uuid'];
        $sources[$id] = [
            'id' => $id, 'type' => 'nvidia', 'name' => "GPU {$g['index']}: {$g['name']}", 'label' => $label($id),
            'temp' => $g['temp'], 'state' => $g['temp'] === null ? 'error' : 'ok',
            'detail' => $g['temp'] === null ? 'temperature not available' : '',
        ];
    }

    // Kernel sensors
    foreach ($hw['temp'] as $key => $t) {
        $id = "hwmon:$key";
        $sources[$id] = [
            'id' => $id, 'type' => 'hwmon', 'name' => $t['chip'] . ' ' . ($t['driver_label'] !== '' ? $t['driver_label'] : "temp{$t['index']}"),
            'label' => $label($id), 'temp' => $t['temp'], 'state' => $t['temp'] === null ? 'error' : 'ok',
            'detail' => $t['temp'] === null ? 'temperature not available' : '',
        ];
    }
    return $sources;
}

/** Sort order of the disk sources: array, pools, unassigned, flash. */
function pfc_disk_order(string $id): int {
    return strpos($id, 'pool:') === 0 ? 1 : (['array' => 0, 'unassigned' => 2, 'flash' => 3][$id] ?? 4);
}

function pfc_source_name(array $src): string {
    return $src['label'] !== '' ? $src['label'] : $src['name'];
}

/* ------------------------------------------------------------------ curves */

/** Linear curve: min_pwm at or below low, max_pwm at or above high. */
function pfc_curve_pwm(float $temp, array $b): int {
    if ($temp <= $b['low']) return (int)$b['min_pwm'];
    if ($temp >= $b['high']) return (int)$b['max_pwm'];
    return (int)round($b['min_pwm'] + ($temp - $b['low']) * ($b['max_pwm'] - $b['min_pwm']) / ($b['high'] - $b['low']));
}

/** Speed one source binding asks for. Returns ['pwm' => int, 'reason' => string]. */
function pfc_binding_demand(array $b, ?array $src): array {
    if (!$src) return ['pwm' => PFC_FAILSAFE_PWM, 'reason' => 'A temperature source is not available, running at full speed'];
    $name = pfc_source_name($src);
    switch ($src['state']) {
        case 'error':  return ['pwm' => PFC_FAILSAFE_PWM, 'reason' => "$name: {$src['detail']}, running at full speed"];
        case 'parity': return ['pwm' => (int)$b['max_pwm'], 'reason' => "$name: {$src['detail']}"];
        case 'idle':   return ['pwm' => (int)($b['idle_pwm'] ?? $b['min_pwm']), 'reason' => "$name: {$src['detail']}"];
    }
    return ['pwm' => pfc_curve_pwm($src['temp'], $b), 'reason' => "$name at " . pfc_fmt_temp($src['temp'])];
}

/**
 * What a group wants right now (before the slow-down delay): the highest demand of its sources.
 * Returns ['pwm' => int, 'reason' => string, 'source' => ?id, 'demands' => [id => pwm]].
 */
function pfc_group_demand(array $group, array $sources): array {
    $best = null;
    $demands = [];
    foreach ($group['sources'] as $b) {
        $d = pfc_binding_demand($b, $sources[$b['source']] ?? null);
        $demands[$b['source']] = $d['pwm'];
        if ($best === null || $d['pwm'] > $best['pwm']) $best = $d + ['source' => $b['source']];
    }
    if ($best === null) return ['pwm' => PFC_FAILSAFE_PWM, 'reason' => 'No temperature sources, running at full speed', 'source' => null, 'demands' => []];
    return $best + ['demands' => $demands];
}
