<?php
/*
 * PWM Fan Control - shared helpers for the web UI, API, daemon and scripts.
 *
 * Concepts
 *   Fan     A PWM output on a motherboard/controller chip ("<chip>@<device>/pwmN"). Keys use the chip name
 *           instead of /sys/class/hwmon/hwmonX, because the hwmonX number can change between boots.
 *   Source  Something that reports a temperature:
 *             "array"                  hottest included array/pool/unassigned disk
 *             "nvidia:<GPU uuid>"      an Nvidia GPU (via nvidia-smi)
 *             "hwmon:<chip>/tempN"     any kernel temperature sensor (CPU, motherboard, NVMe, ...)
 *   Group   A set of fans following one or more sources. Every source has its own curve and the
 *           group runs at the highest speed any of its sources asks for.
 */

const PFC_NAME       = 'pwm.fan.control';
const PFC_PLUGIN     = '/usr/local/emhttp/plugins/' . PFC_NAME;
const PFC_BOOT       = '/boot/config/plugins/' . PFC_NAME;
const PFC_CONFIG     = PFC_BOOT . '/config.json';
const PFC_FANS       = PFC_BOOT . '/fans.json';
const PFC_CRON       = PFC_BOOT . '/' . PFC_NAME . '.cron';
const PFC_RUN        = '/tmp/' . PFC_NAME;
const PFC_STATUS     = PFC_RUN . '/status.json';
const PFC_HISTORY    = PFC_RUN . '/history.json';
const PFC_IDENTIFY   = PFC_RUN . '/identify.json';
const PFC_STOP_FLAG  = PFC_RUN . '/identify.stop';
const PFC_NV_CACHE   = PFC_RUN . '/nvidia.json';
const PFC_PIDFILE    = '/var/run/' . PFC_NAME . '.pid';
const PFC_RC         = PFC_PLUGIN . '/scripts/rc.pwmfancontrol';
const PFC_HWMON      = '/sys/class/hwmon';
const PFC_NVIDIA_SMI = '/usr/bin/nvidia-smi';
const PFC_EMHTTP     = '/var/local/emhttp';

const PFC_OLD_BOOT   = '/boot/config/plugins/array.fan.control';
const PFC_OLD_PLUGIN = '/usr/local/emhttp/plugins/array.fan.control';

const PFC_HISTORY_MAX   = 1440;  // samples
const PFC_HISTORY_EVERY = 60;    // seconds between samples (24 hours in total)
const PFC_FAILSAFE_PWM  = 255;
const PFC_INTERVALS     = [2, 3, 5, 10, 15, 30];

/* ------------------------------------------------------------------ config */

function pfc_default_config(): array {
    return [
        'enabled'     => false,
        'interval'    => 5,
        'log_changes' => true,
        'identify'    => ['low_pwm' => 0, 'seconds' => 8],
        'array'       => [
            'include' => ['Parity' => true, 'Data' => true, 'Cache' => true, 'Unassigned' => true, 'Flash' => false],
            'exclude' => [],
            'parity_full_speed' => true,
        ],
        'groups'      => [],
    ];
}

/** Default curve for a new source binding, depending on the kind of source. */
function pfc_default_binding(string $source): array {
    if ($source === 'array') return ['source' => $source, 'low' => 35, 'high' => 48, 'min_pwm' => 170, 'max_pwm' => 255, 'idle_pwm' => 170];
    if (strpos($source, 'nvidia:') === 0) return ['source' => $source, 'low' => 45, 'high' => 75, 'min_pwm' => 90, 'max_pwm' => 255];
    return ['source' => $source, 'low' => 40, 'high' => 70, 'min_pwm' => 90, 'max_pwm' => 255];
}

function pfc_load_config(): array {
    $saved = pfc_read_json(PFC_CONFIG);
    if (!is_array($saved)) return pfc_default_config();
    return pfc_normalize_config($saved);
}

/** Fixes types and fills in defaults, so the rest of the code can trust the structure. */
function pfc_normalize_config(array $cfg): array {
    $out = pfc_default_config();
    if (array_key_exists('enabled', $cfg)) $out['enabled'] = !empty($cfg['enabled']);
    if (isset($cfg['interval'])) $out['interval'] = (int)$cfg['interval'];
    if (array_key_exists('log_changes', $cfg)) $out['log_changes'] = !empty($cfg['log_changes']);
    if (isset($cfg['identify']['low_pwm'])) $out['identify']['low_pwm'] = (int)$cfg['identify']['low_pwm'];
    if (isset($cfg['identify']['seconds'])) $out['identify']['seconds'] = (int)$cfg['identify']['seconds'];
    foreach ($out['array']['include'] as $type => $default) {
        if (isset($cfg['array']['include']) && is_array($cfg['array']['include'])) {
            $out['array']['include'][$type] = !empty($cfg['array']['include'][$type]);
        }
    }
    $exclude = $cfg['array']['exclude'] ?? [];
    $out['array']['exclude'] = array_values(array_filter(array_map('strval', is_array($exclude) ? $exclude : [])));
    if (isset($cfg['array']) && array_key_exists('parity_full_speed', $cfg['array'])) {
        $out['array']['parity_full_speed'] = !empty($cfg['array']['parity_full_speed']);
    }

    foreach (is_array($cfg['groups'] ?? null) ? $cfg['groups'] : [] as $g) {
        if (!is_array($g)) continue;
        $group = [
            'id'        => preg_replace('/[^a-z0-9_-]/i', '', (string)($g['id'] ?? '')) ?: pfc_new_id(),
            'name'      => pfc_clean_label($g['name'] ?? ''),
            'fans'      => array_values(array_unique(array_map('strval', is_array($g['fans'] ?? null) ? $g['fans'] : []))),
            'ramp_down' => (int)($g['ramp_down'] ?? 30),
            'sources'   => [],
        ];
        foreach (is_array($g['sources'] ?? null) ? $g['sources'] : [] as $b) {
            if (!is_array($b) || empty($b['source'])) continue;
            $d = pfc_default_binding((string)$b['source']);
            $binding = ['source' => (string)$b['source']];
            foreach (['low', 'high', 'min_pwm', 'max_pwm', 'idle_pwm'] as $k) {
                if (array_key_exists($k, $d)) $binding[$k] = isset($b[$k]) && is_numeric($b[$k]) ? (int)$b[$k] : $d[$k];
            }
            $group['sources'][] = $binding;
        }
        $out['groups'][] = $group;
    }
    return $out;
}

/** Returns a list of human readable problems. Empty list = valid. */
function pfc_validate_config(array $cfg): array {
    $errors = [];
    if (!in_array($cfg['interval'], PFC_INTERVALS, true)) $errors[] = 'Check interval must be one of ' . implode(', ', PFC_INTERVALS) . ' seconds.';
    if ($cfg['identify']['low_pwm'] < 0 || $cfg['identify']['low_pwm'] > 255) $errors[] = 'Identify speed must be between 0 and 255.';
    if ($cfg['identify']['seconds'] < 3 || $cfg['identify']['seconds'] > 30) $errors[] = 'Identify duration must be between 3 and 30 seconds.';

    $owner = [];
    $ids = [];
    foreach ($cfg['groups'] as $g) {
        $n = $g['name'] !== '' ? $g['name'] : 'Unnamed group';
        if ($g['name'] === '') $errors[] = 'Every group needs a name.';
        if (isset($ids[$g['id']])) $errors[] = "Group \"$n\" has a duplicate id.";
        $ids[$g['id']] = true;
        if ($g['ramp_down'] < 0 || $g['ramp_down'] > 600) $errors[] = "\"$n\": slow-down delay must be between 0 and 600 seconds.";
        if ($g['fans'] && !$g['sources']) $errors[] = "\"$n\": add at least one temperature source.";
        foreach ($g['fans'] as $fan) {
            if (isset($owner[$fan])) $errors[] = "Fan $fan is in both \"{$owner[$fan]}\" and \"$n\". A fan can only be in one group.";
            $owner[$fan] = $n;
        }
        $seen = [];
        foreach ($g['sources'] as $b) {
            if (isset($seen[$b['source']])) $errors[] = "\"$n\": the same source is added twice.";
            $seen[$b['source']] = true;
            if ($b['low'] >= $b['high']) $errors[] = "\"$n\": low temperature ({$b['low']}) must be below high temperature ({$b['high']}).";
            if ($b['min_pwm'] < 0 || $b['max_pwm'] > 255 || $b['min_pwm'] >= $b['max_pwm']) $errors[] = "\"$n\": minimum speed ({$b['min_pwm']}) must be below maximum speed ({$b['max_pwm']}), within 0-255.";
            if (isset($b['idle_pwm']) && ($b['idle_pwm'] < 0 || $b['idle_pwm'] > 255)) $errors[] = "\"$n\": spun-down speed must be between 0 and 255.";
        }
    }
    return $errors;
}

function pfc_save_config(array $cfg): void {
    @mkdir(PFC_BOOT, 0777, true);
    pfc_write_atomic(PFC_CONFIG, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function pfc_new_id(): string {
    return 'g' . bin2hex(random_bytes(4));
}

/** All fan keys that belong to a group, mapped to the group id. */
function pfc_controlled_keys(array $cfg): array {
    $keys = [];
    foreach ($cfg['groups'] as $g) foreach ($g['fans'] as $f) $keys[$f] = $g['id'];
    return $keys;
}

/**
 * One-time import of settings from the earlier "Array Fan Control" plugin:
 * fan names/pairings and an "Array fans" group with the old curve.
 */
function pfc_migrate_old(): bool {
    if (is_file(PFC_CONFIG) || !is_dir(PFC_OLD_BOOT)) return false;
    $old = @parse_ini_file(PFC_OLD_BOOT . '/array.fan.control.cfg') ?: [];
    $old_fans = pfc_read_json(PFC_OLD_BOOT . '/fans.json', []);

    $yes = fn($k, $d) => ($old[$k] ?? $d) === 'yes';
    $cfg = [
        'enabled' => false, // stays off until the old plugin is removed, so the two never fight over the fans
        'log_changes' => $yes('LOG_CHANGES', 'yes'),
        'identify' => ['low_pwm' => (int)($old['IDENTIFY_LOW_PWM'] ?? 0), 'seconds' => (int)($old['IDENTIFY_SECONDS'] ?? 8)],
        'array' => [
            'include' => [
                'Parity' => $yes('INCLUDE_PARITY', 'yes'), 'Data' => $yes('INCLUDE_DATA', 'yes'), 'Cache' => $yes('INCLUDE_CACHE', 'yes'),
                'Unassigned' => $yes('INCLUDE_UNASSIGNED', 'yes'), 'Flash' => $yes('INCLUDE_FLASH', 'no'),
            ],
            'exclude' => array_values(array_filter(array_map('trim', explode(',', $old['EXCLUDE_DISKS'] ?? '')))),
            'parity_full_speed' => $yes('PARITY_FULL_SPEED', 'yes'),
        ],
        'groups' => [],
    ];

    $fans = ['pwm' => [], 'rpm' => $old_fans['rpm'] ?? [], 'temp' => []];
    $controlled = [];
    foreach ($old_fans['pwm'] ?? [] as $key => $f) {
        $fans['pwm'][$key] = array_intersect_key($f, ['label' => 1, 'rpm' => 1, 'orig_enable' => 1]);
        if (!empty($f['controlled'])) $controlled[] = $key;
    }
    $cfg['groups'][] = [
        'id' => pfc_new_id(), 'name' => 'Array fans', 'fans' => $controlled, 'ramp_down' => 0,
        'sources' => [[
            'source' => 'array', 'low' => (int)($old['LOW_TEMP'] ?? 35), 'high' => (int)($old['HIGH_TEMP'] ?? 48),
            'min_pwm' => (int)($old['MIN_PWM'] ?? 170), 'max_pwm' => (int)($old['MAX_PWM'] ?? 255), 'idle_pwm' => (int)($old['IDLE_PWM'] ?? 170),
        ]],
    ];
    pfc_save_config(pfc_normalize_config($cfg));
    pfc_save_fans($fans);
    return true;
}

/* -------------------------------------------------------------------- fans */

/**
 * fans.json: user names and pairings.
 * {
 *   "pwm":  { "<fan key>":  { "label": "...", "rpm": "<rpm key>|none", "orig_enable": "5" } },
 *   "rpm":  { "<rpm key>":  { "label": "..." } },
 *   "temp": { "<source id>": { "label": "..." } }
 * }
 */
function pfc_load_fans(): array {
    $data = pfc_read_json(PFC_FANS, []);
    if (!is_array($data)) $data = [];
    foreach (['pwm', 'rpm', 'temp'] as $k) $data[$k] = is_array($data[$k] ?? null) ? $data[$k] : [];
    return $data;
}

function pfc_save_fans(array $fans): void {
    @mkdir(PFC_BOOT, 0777, true);
    pfc_write_atomic(PFC_FANS, json_encode($fans, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Scans /sys/class/hwmon for PWM outputs, RPM sensors and temperature sensors.
 * Returns ['pwm' => [key => info], 'rpm' => [key => info], 'temp' => [key => info]].
 */
function pfc_scan_hwmon(): array {
    $result = ['pwm' => [], 'rpm' => [], 'temp' => []];
    $used = [];
    $dirs = glob(PFC_HWMON . '/hwmon*') ?: [];
    natsort($dirs);

    foreach ($dirs as $dir) {
        $chip = trim((string)@file_get_contents("$dir/name"));
        if ($chip === '') $chip = basename($dir);
        $device = @realpath("$dir/device");
        $chip_id = $chip . ($device ? '@' . basename($device) : '');
        if (isset($used[$chip_id])) $chip_id .= '#' . basename($dir);
        $used[$chip_id] = true;

        foreach (glob("$dir/fan*_input") ?: [] as $file) {
            if (!preg_match('/\/fan(\d+)_input$/', $file, $m)) continue;
            $n = (int)$m[1];
            $result['rpm']["$chip_id/fan$n"] = [
                'key' => "$chip_id/fan$n", 'chip' => $chip, 'index' => $n, 'path' => $file,
                'driver_label' => trim((string)@file_get_contents("$dir/fan{$n}_label")),
                'rpm' => pfc_read_int($file),
            ];
        }

        foreach (glob("$dir/temp*_input") ?: [] as $file) {
            if (!preg_match('/\/temp(\d+)_input$/', $file, $m)) continue;
            $n = (int)$m[1];
            $milli = pfc_read_int($file);
            $result['temp']["$chip_id/temp$n"] = [
                'key' => "$chip_id/temp$n", 'chip' => $chip, 'index' => $n, 'path' => $file,
                'driver_label' => trim((string)@file_get_contents("$dir/temp{$n}_label")),
                'temp' => $milli === null ? null : round($milli / 1000, 1),
            ];
        }

        foreach (glob("$dir/pwm*") ?: [] as $file) {
            if (!preg_match('/\/pwm(\d+)$/', $file, $m)) continue;
            $n = (int)$m[1];
            $result['pwm']["$chip_id/pwm$n"] = [
                'key' => "$chip_id/pwm$n", 'chip' => $chip, 'index' => $n, 'path' => $file, 'hwmon' => basename($dir),
                'value'  => pfc_read_int($file),
                'enable' => is_file("{$file}_enable") ? pfc_read_int("{$file}_enable") : null,
                'mode'   => is_file("{$file}_mode") ? pfc_read_int("{$file}_mode") : null,
                'writable'  => is_writable($file),
                // Most drivers pair pwmN with fanN_input, but this is only a guess
                'guess_rpm' => isset($result['rpm']["$chip_id/fan$n"]) ? "$chip_id/fan$n" : null,
            ];
        }
    }
    foreach ($result as &$list) uksort($list, 'strnatcmp');
    unset($list);
    return $result;
}

function pfc_enable_mode_name($enable): string {
    $names = [0 => 'Full speed', 1 => 'Manual', 2 => 'Thermal cruise', 3 => 'Fan speed cruise', 4 => 'Smart Fan III', 5 => 'Smart Fan IV'];
    return $enable === null ? 'n/a' : ($names[$enable] ?? "Mode $enable");
}

/**
 * Puts a fan back under BIOS/driver control (the mode it had before we took it over).
 * Safe to call more than once: a fan that is no longer in manual mode is left alone.
 */
function pfc_release_fan(array &$fans, string $key, array $hw): void {
    $orig = $fans['pwm'][$key]['orig_enable'] ?? null;
    unset($fans['pwm'][$key]['orig_enable']);
    if (!isset($hw['pwm'][$key])) return;
    $fan = $hw['pwm'][$key];
    if ($orig !== null && $orig !== '' && $orig != 1 && is_writable("{$fan['path']}_enable")) {
        @file_put_contents("{$fan['path']}_enable", (string)$orig);
    } elseif ($fan['enable'] === null || $fan['enable'] === 1) {
        // Was already manual before we took it, or has no mode: leave it at full speed to be safe
        @file_put_contents($fan['path'], '255');
    }
}

/** Releases every fan we control or have taken over. */
function pfc_release_all(?array $cfg = null): void {
    $cfg = $cfg ?? pfc_load_config();
    $fans = pfc_load_fans();
    $hw = pfc_scan_hwmon();
    $keys = array_keys(pfc_controlled_keys($cfg));
    foreach ($fans['pwm'] as $key => $f) if (isset($f['orig_enable'])) $keys[] = $key;
    foreach (array_unique($keys) as $key) pfc_release_fan($fans, $key, $hw);
    pfc_save_fans($fans);
}

/* ----------------------------------------------------------------- sources */

/** Unraid's disks (array, pools and unassigned devices). */
function pfc_read_disks(array $cfg): array {
    $include = $cfg['array']['include'];
    $exclude = $cfg['array']['exclude'];
    $disks = [];

    $sources = [[PFC_EMHTTP . '/disks.ini', null]];
    foreach ([PFC_EMHTTP . '/devs.ini', '/usr/local/emhttp/state/devs.ini'] as $devs) {
        if (is_file($devs)) { $sources[] = [$devs, 'Unassigned']; break; }
    }
    foreach ($sources as [$file, $force_type]) {
        if (!is_file($file)) continue;
        $ini = @parse_ini_file($file, true);
        if (!is_array($ini)) continue;
        foreach ($ini as $section => $d) {
            if (!is_array($d)) continue;
            $type = $force_type ?? (string)($d['type'] ?? '');
            $temp = trim((string)($d['temp'] ?? ''), '"');
            $disk = [
                'name' => (string)$section, 'type' => $type, 'device' => (string)($d['id'] ?? ''),
                'temp' => preg_match('/^\d+$/', $temp) ? (int)$temp : null,
                'spundown' => ($d['spundown'] ?? '0') == '1',
                'included' => true, 'reason' => '',
            ];
            if ($disk['device'] === '') { $disk['included'] = false; $disk['reason'] = 'Not assigned'; }
            elseif (empty($include[$type])) { $disk['included'] = false; $disk['reason'] = "$type disks not monitored"; }
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

    $collect = function () use ($out, $rcf, $pidf) {
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
    $launch = function () use ($out, $rcf, $pidf) {
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

/**
 * Reads every available temperature source.
 * Each source: ['id', 'type', 'name' (default name), 'label' (user name), 'temp' (?float),
 *               'state' => ok|idle|parity|error, 'detail' => string]
 */
function pfc_read_sources(array $cfg, array $hw, array $fans, ?array $disks = null, ?array $nv = null): array {
    $sources = [];
    $label = fn($id) => (string)($fans['temp'][$id]['label'] ?? '');

    // Array: hottest included, spun-up disk
    $disks = $disks ?? pfc_read_disks($cfg);
    $included = array_filter($disks, fn($d) => $d['included']);
    $active = array_filter($included, fn($d) => !$d['spundown']);
    $hot = null;
    foreach ($active as $d) if ($d['temp'] !== null && ($hot === null || $d['temp'] > $hot['temp'])) $hot = $d;
    $src = ['id' => 'array', 'type' => 'array', 'name' => 'Array disks', 'label' => $label('array'), 'temp' => $hot['temp'] ?? null, 'state' => 'ok', 'detail' => $hot ? "hottest is {$hot['name']}" : ''];
    if (!$included) { $src['state'] = 'error'; $src['detail'] = 'no disks are monitored'; }
    elseif ($cfg['array']['parity_full_speed'] && pfc_parity_running()) { $src['state'] = 'parity'; $src['detail'] = 'parity check/rebuild running'; }
    elseif (!$active) { $src['state'] = 'idle'; $src['detail'] = 'all disks spun down'; }
    elseif (!$hot) { $src['state'] = 'error'; $src['detail'] = 'no disk temperature available'; }
    $sources['array'] = $src;

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

/* ----------------------------------------------------------------- curves */

/** Linear curve: min_pwm at or below low, max_pwm at or above high. */
function pfc_curve_pwm(float $temp, array $b): int {
    if ($temp <= $b['low']) return (int)$b['min_pwm'];
    if ($temp >= $b['high']) return (int)$b['max_pwm'];
    return (int)round($b['min_pwm'] + ($temp - $b['low']) * ($b['max_pwm'] - $b['min_pwm']) / ($b['high'] - $b['low']));
}

/** Speed one source binding asks for. Returns ['pwm' => int, 'reason' => string]. */
function pfc_binding_demand(array $b, ?array $src, string $name): array {
    if (!$src) return ['pwm' => PFC_FAILSAFE_PWM, 'reason' => "$name is not available, running at full speed"];
    switch ($src['state']) {
        case 'error':  return ['pwm' => PFC_FAILSAFE_PWM, 'reason' => "$name: {$src['detail']}, running at full speed"];
        case 'parity': return ['pwm' => (int)$b['max_pwm'], 'reason' => "$name: {$src['detail']}"];
        case 'idle':   return ['pwm' => (int)($b['idle_pwm'] ?? $b['min_pwm']), 'reason' => "$name: {$src['detail']}"];
    }
    return ['pwm' => pfc_curve_pwm($src['temp'], $b), 'reason' => "$name at " . pfc_fmt_temp($src['temp'])];
}

/**
 * What a group wants right now (before the slow-down delay).
 * Returns ['pwm' => int, 'reason' => string, 'source' => ?id, 'demands' => [id => pwm]].
 */
function pfc_group_demand(array $group, array $sources): array {
    $best = null;
    $demands = [];
    foreach ($group['sources'] as $b) {
        $src = $sources[$b['source']] ?? null;
        $d = pfc_binding_demand($b, $src, $src ? pfc_source_name($src) : 'A temperature source');
        $demands[$b['source']] = $d['pwm'];
        if ($best === null || $d['pwm'] > $best['pwm']) $best = $d + ['source' => $b['source']];
    }
    if ($best === null) return ['pwm' => PFC_FAILSAFE_PWM, 'reason' => 'No temperature sources, running at full speed', 'source' => null, 'demands' => []];
    return $best + ['demands' => $demands];
}

function pfc_source_name(array $src): string {
    return $src['label'] !== '' ? $src['label'] : $src['name'];
}

function pfc_fmt_temp($t): string {
    return $t === null ? 'n/a' : rtrim(rtrim(number_format((float)$t, 1, '.', ''), '0'), '.') . '°C';
}

/* -------------------------------------------------------------- daemon/cron */

function pfc_daemon_pid(): ?int {
    $pid = (int)trim((string)@file_get_contents(PFC_PIDFILE));
    return pfc_pid_alive($pid) ? $pid : null;
}

/** Starts or stops the daemon and its watchdog cron job to match the config. */
function pfc_apply_service(array $cfg): void {
    @mkdir(PFC_BOOT, 0777, true);
    if ($cfg['enabled']) {
        file_put_contents(PFC_CRON, "# PWM Fan Control watchdog (generated, do not edit)\n* * * * * " . PFC_RC . " watchdog >/dev/null 2>&1\n\n");
    } else {
        @unlink(PFC_CRON);
    }
    exec('/usr/local/sbin/update_cron');
    exec(PFC_RC . ($cfg['enabled'] ? ' start' : ' stop') . ' >/dev/null 2>&1');
}

/* ------------------------------------------------------------------- misc */

function pfc_read_int(string $file): ?int {
    $v = @file_get_contents($file);
    return ($v === false || !is_numeric(trim($v))) ? null : (int)trim($v);
}

function pfc_read_json(string $file, $default = null) {
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    return $data ?? $default;
}

function pfc_write_atomic(string $file, string $content): void {
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $content) !== false) @rename($tmp, $file);
}

function pfc_write_json(string $file, $data): void {
    @mkdir(dirname($file), 0777, true);
    pfc_write_atomic($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function pfc_log(string $msg): void {
    exec('logger -t ' . escapeshellarg(PFC_NAME) . ' ' . escapeshellarg($msg));
}

function pfc_pid_alive($pid): bool {
    return is_numeric($pid) && (int)$pid > 0 && is_dir('/proc/' . (int)$pid);
}

/** The identify run currently in progress, or null. */
function pfc_identify_running(): ?array {
    $state = pfc_read_json(PFC_IDENTIFY);
    if (!is_array($state) || ($state['phase'] ?? '') === 'done') return null;
    return pfc_pid_alive($state['pid'] ?? 0) ? $state : null;
}

/** Trims a user supplied name to plain text, max 40 characters. */
function pfc_clean_label($label): string {
    $label = trim(strip_tags((string)$label));
    return function_exists('mb_substr') ? mb_substr($label, 0, 40) : substr($label, 0, 40);
}
