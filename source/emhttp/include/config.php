<?php
/*
 * PWM Fan Control - settings and fan groups (config.json on the flash drive).
 *
 * {
 *   "enabled": false, "interval": 5, "log_changes": false, "notify": true, "drivers": "nct6775",
 *   "identify": {"low_pwm": 51, "seconds": 8},
 *   "array": {"include": {"Parity": true, ...}, "exclude": ["disk3"], "parity_full_speed": true},
 *   "groups": [{"id": "g1a2b", "name": "Array fans", "fans": ["<fan key>"], "ramp_down": 30,
 *               "sources": [{"source": "array", "low": 35, "high": 48, "min_pwm": 170, "max_pwm": 255, "idle_pwm": 170}]}]
 * }
 * Speeds are stored as PWM values (0-255). The web UI shows them as percentages.
 */

function pfc_default_config(): array {
    return [
        'enabled'     => false,
        'interval'    => 5,
        'log_changes' => false,
        'notify'      => true,
        'drivers'     => '',      // kernel modules to load, e.g. "nct6775" or "it87 force_id=0x8628; nct6775"
        'identify'    => ['low_pwm' => 51, 'seconds' => 8],   // 20%: slows fans clearly without stopping them
        'array'       => [
            // Only read when converting an older config (see pfc_migrate_disk_sources)
            'include' => ['Parity' => true, 'Data' => true, 'Cache' => true, 'Unassigned' => true, 'Flash' => false],
            'exclude' => [],                // disks not used by their disk source
            'parity_full_speed' => true,    // the Array source asks for max speed during a parity check
        ],
        'groups'      => [],
        'disk_sources' => 2,                // 1: older configs, one "array" source for every disk
    ];
}

/** Default curve for a new source binding, depending on the kind of source. */
function pfc_default_binding(string $source): array {
    if ($source === 'array' || $source === 'unassigned') return ['source' => $source, 'low' => 35, 'high' => 48, 'min_pwm' => 170, 'max_pwm' => 255, 'idle_pwm' => 170];
    if (pfc_is_disk_source($source)) return ['source' => $source, 'low' => 45, 'high' => 65, 'min_pwm' => 90, 'max_pwm' => 255, 'idle_pwm' => 90]; // SSD/NVMe pools, flash
    if (strpos($source, 'nvidia:') === 0) return ['source' => $source, 'low' => 45, 'high' => 75, 'min_pwm' => 90, 'max_pwm' => 255];
    return ['source' => $source, 'low' => 40, 'high' => 70, 'min_pwm' => 90, 'max_pwm' => 255];
}

function pfc_load_config(): array {
    $saved = pfc_read_json(PFC_CONFIG);
    if (!is_array($saved)) return pfc_default_config();
    $cfg = pfc_normalize_config($saved);
    if ($cfg['disk_sources'] < 2) {
        // Needs Unraid's disk list to know the pools. Until emhttp has written it, the config is used as it is.
        $disks = pfc_read_disks($cfg);
        if ($disks) {
            $cfg = pfc_migrate_disk_sources($cfg, $disks);
            pfc_save_config($cfg);
        }
    }
    return $cfg;
}

/**
 * Converts a config from before the disk sources were split. The single "array" source covered every
 * disk type ticked under "Disk types to monitor". Every group that used it now gets the Array source
 * and a source for each pool (and unassigned/flash disks) that was ticked, all with the same curve, so
 * the fans behave as before until the curves are changed.
 */
function pfc_migrate_disk_sources(array $cfg, array $disks): array {
    $include = $cfg['array']['include'];
    $present = [];
    // Only sources with a disk the old source used: a pool whose disks were all unticked stays out
    foreach ($disks as $d) if ($d['included']) $present[$d['source']] = true;
    $wanted = [];
    if ($include['Parity'] || $include['Data']) $wanted[] = 'array';
    foreach (array_keys($present) as $id) {
        if (strpos($id, 'pool:') === 0 && $include['Cache']) $wanted[] = $id;
        if ($id === 'unassigned' && $include['Unassigned']) $wanted[] = $id;
        if ($id === 'flash' && $include['Flash']) $wanted[] = $id;
    }
    if (!$wanted) $wanted = ['array'];
    foreach ($cfg['groups'] as &$g) {
        $new = [];
        foreach ($g['sources'] as $b) {
            foreach ($b['source'] === 'array' ? $wanted : [$b['source']] as $id) {
                if (!isset($new[$id])) $new[$id] = ['source' => $id] + $b;
            }
        }
        $g['sources'] = array_values($new);
        $names = implode(', ', array_map('pfc_disk_source_name', array_filter(array_column($g['sources'], 'source'), 'pfc_is_disk_source')));
        if ($names !== '') pfc_log("Group \"{$g['name']}\": disk temperatures are now separate sources ($names), with the curve of the former Array disks source");
    }
    unset($g);
    $cfg['disk_sources'] = 2;
    return $cfg;
}

/** Fixes types and fills in defaults, so the rest of the code can trust the structure. */
function pfc_normalize_config(array $cfg): array {
    $out = pfc_default_config();
    foreach (['enabled', 'log_changes', 'notify'] as $k) {
        if (array_key_exists($k, $cfg)) $out[$k] = !empty($cfg[$k]);
    }
    if (isset($cfg['interval'])) $out['interval'] = (int)$cfg['interval'];
    if (isset($cfg['drivers']) && is_string($cfg['drivers'])) {
        $out['drivers'] = implode('; ', array_filter(array_map(fn($e) => preg_replace('/\s+/', ' ', trim($e)), explode(';', $cfg['drivers'])), 'strlen'));
    }
    foreach (['low_pwm', 'seconds'] as $k) {
        if (isset($cfg['identify'][$k]) && is_numeric($cfg['identify'][$k])) $out['identify'][$k] = (int)$cfg['identify'][$k];
    }

    $array = is_array($cfg['array'] ?? null) ? $cfg['array'] : [];
    if (is_array($array['include'] ?? null)) {
        foreach ($out['array']['include'] as $type => $_) $out['array']['include'][$type] = !empty($array['include'][$type]);
    }
    $exclude = is_array($array['exclude'] ?? null) ? $array['exclude'] : [];
    $out['array']['exclude'] = array_values(array_unique(array_filter(array_map('strval', $exclude), 'strlen')));
    if (array_key_exists('parity_full_speed', $array)) $out['array']['parity_full_speed'] = !empty($array['parity_full_speed']);
    $out['disk_sources'] = (int)($cfg['disk_sources'] ?? 1);

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
            $defaults = pfc_default_binding((string)$b['source']);
            $binding = ['source' => (string)$b['source']];
            foreach (['low', 'high', 'min_pwm', 'max_pwm', 'idle_pwm'] as $k) {
                if (array_key_exists($k, $defaults)) $binding[$k] = isset($b[$k]) && is_numeric($b[$k]) ? (int)round((float)$b[$k]) : $defaults[$k];
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
    if ($cfg['identify']['low_pwm'] < 0 || $cfg['identify']['low_pwm'] > 255) $errors[] = 'Identify speed must be between 0% and 100%.';
    if ($cfg['identify']['seconds'] < 3 || $cfg['identify']['seconds'] > 30) $errors[] = 'Identify duration must be between 3 and 30 seconds.';
    if (pfc_driver_list($cfg) === null) $errors[] = 'Drivers to load: use module names with optional options, separated by ";", e.g. "it87 force_id=0x8628; nct6775".';

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
            if ($b['low'] < -20 || $b['high'] > 150) $errors[] = "\"$n\": temperatures must be between -20 and 150 °C.";
            if ($b['low'] >= $b['high']) $errors[] = "\"$n\": low temperature ({$b['low']} °C) must be below high temperature ({$b['high']} °C).";
            if ($b['min_pwm'] < 0 || $b['max_pwm'] > 255 || $b['min_pwm'] > $b['max_pwm']) {
                $errors[] = "\"$n\": min speed (" . pfc_pct($b['min_pwm']) . '%) must not be above max speed (' . pfc_pct($b['max_pwm']) . '%), within 0-100%.';
            }
            if (isset($b['idle_pwm']) && ($b['idle_pwm'] < 0 || $b['idle_pwm'] > 255)) $errors[] = "\"$n\": spun-down speed must be between 0% and 100%.";
        }
    }
    return array_values(array_unique($errors));
}

function pfc_save_config(array $cfg): bool {
    return pfc_write_flash_json(PFC_CONFIG, $cfg);
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
 * The "drivers" setting as [module => [options]], or null when it is not valid.
 * Only plain module names and name=value options are accepted, nothing else reaches modprobe.
 */
function pfc_driver_list(array $cfg): ?array {
    $list = [];
    foreach (explode(';', $cfg['drivers']) as $entry) {
        $parts = preg_split('/\s+/', trim($entry), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) continue;
        $module = array_shift($parts);
        if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $module)) return null;
        foreach ($parts as $p) if (!preg_match('/^[a-z0-9_]{1,64}=[a-z0-9_.,:-]{1,64}$/i', $p)) return null;
        $list[$module] = $parts;
    }
    return $list;
}

/** Whether any group follows a GPU (only then nvidia-smi has to run). */
function pfc_uses_nvidia(array $cfg): bool {
    foreach ($cfg['groups'] as $g) foreach ($g['sources'] as $b) if (strpos($b['source'], 'nvidia:') === 0) return true;
    return false;
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
        'log_changes' => $yes('LOG_CHANGES', 'no'),
        'identify' => ['low_pwm' => (int)($old['IDENTIFY_LOW_PWM'] ?? 51), 'seconds' => (int)($old['IDENTIFY_SECONDS'] ?? 8)],
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

    $fans = ['pwm' => [], 'rpm' => is_array($old_fans['rpm'] ?? null) ? $old_fans['rpm'] : [], 'temp' => []];
    $controlled = [];
    foreach (is_array($old_fans['pwm'] ?? null) ? $old_fans['pwm'] : [] as $key => $f) {
        $fans['pwm'][$key] = array_intersect_key((array)$f, ['label' => 1, 'rpm' => 1]);
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
