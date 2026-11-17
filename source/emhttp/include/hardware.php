<?php
/*
 * PWM Fan Control - fans and kernel sensors (/sys/class/hwmon) and fan names (fans.json).
 *
 * fans.json on the flash drive:
 * {
 *   "pwm":  { "<fan key>":  { "label": "...", "rpm": "<rpm key>|none", "orig_enable": "5", "test": {...} } },
 *   "rpm":  { "<rpm key>":  { "label": "..." } },
 *   "temp": { "<source id>": { "label": "..." } }
 * }
 *   rpm          RPM sensor paired with the output, set by Identify, the speed test or by hand
 *   orig_enable  the BIOS mode of the output before the plugin took it over, restored on release
 *   test         result of the last speed test, see scripts/fantest.php
 */

function pfc_load_fans(): array {
    $data = pfc_read_json(PFC_FANS, []);
    if (!is_array($data)) $data = [];
    foreach (['pwm', 'rpm', 'temp'] as $k) $data[$k] = is_array($data[$k] ?? null) ? $data[$k] : [];
    return $data;
}

function pfc_save_fans(array $fans): bool {
    return pfc_write_flash_json(PFC_FANS, $fans);
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

        // Disk temperatures come from Unraid (Array disks source). Reading drivetemp sensors directly
        // sends commands to the disks, which can keep them from spinning down.
        if ($chip !== 'drivetemp') {
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
        }

        foreach (glob("$dir/pwm*") ?: [] as $file) {
            if (!preg_match('/\/pwm(\d+)$/', $file, $m)) continue;
            $n = (int)$m[1];
            $result['pwm']["$chip_id/pwm$n"] = [
                'key' => "$chip_id/pwm$n", 'chip' => $chip, 'index' => $n, 'path' => $file, 'hwmon' => basename($dir),
                'value'  => pfc_read_int($file),
                'enable' => is_file("{$file}_enable") ? pfc_read_int("{$file}_enable") : null,
                'mode'   => is_file("{$file}_mode") ? pfc_read_int("{$file}_mode") : null, // 0 = DC, 1 = PWM
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

/** The RPM sensor confirmed for a fan (by Identify, the speed test or by hand), or null. */
function pfc_confirmed_rpm(array $fans, string $key): ?string {
    $rpm = $fans['pwm'][$key]['rpm'] ?? null;
    return is_string($rpm) && $rpm !== 'none' ? $rpm : null;
}

/**
 * Switches an output to manual control and sets its speed.
 * Remembers the BIOS mode in $fans the first time (returns $changed = true then).
 * Returns null on success, or an error message.
 */
function pfc_drive_fan(array &$fans, array $fan, int $pwm, bool &$changed): ?string {
    $key = $fan['key'];
    if ($fan['enable'] !== null && $fan['enable'] !== 1) {
        if (!isset($fans['pwm'][$key]['orig_enable'])) {
            $fans['pwm'][$key]['orig_enable'] = (string)$fan['enable'];
            $changed = true;
        }
        if (@file_put_contents("{$fan['path']}_enable", '1') === false) return 'could not switch it to manual control';
    }
    if ($fan['value'] !== $pwm && @file_put_contents($fan['path'], (string)$pwm) === false) return 'could not set its speed';
    return null;
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
        @file_put_contents($fan['path'], (string)PFC_FAILSAFE_PWM);
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

/** Every PWM output with its name, pairing, group and current RPM, for the web UI. */
function pfc_fan_list(array $cfg, array $fans, array $hw): array {
    $owner = pfc_controlled_keys($cfg);
    $taken = [];  // RPM sensors confirmed for an output can't be another output's guess
    foreach ($hw['pwm'] as $key => $_) if ($r = pfc_confirmed_rpm($fans, $key)) $taken[$r] = $key;
    $list = [];
    foreach ($hw['pwm'] as $key => $p) {
        $f = $fans['pwm'][$key] ?? [];
        if ($p['guess_rpm'] !== null && isset($taken[$p['guess_rpm']]) &&$taken[$p['guess_rpm']] !== $key) $p['guess_rpm'] = null;
        $rpm_key = $f['rpm'] ?? $p['guess_rpm'];
        unset($p['path']);
        $list[] = $p + [
            'label'         => (string)($f['label'] ?? ''),
            'group'         => $owner[$key] ?? null,
            'rpm_key'       => $rpm_key,
            'rpm_confirmed' => isset($f['rpm']),
            'rpm'           => $rpm_key !== null ? ($hw['rpm'][$rpm_key]['rpm'] ?? null) : null,
            'enable_name'   => pfc_enable_mode_name($p['enable']),
            'test'          => $f['test'] ?? null,
        ];
    }
    return $list;
}

/** Every RPM sensor with its name, for the web UI. */
function pfc_rpm_list(array $fans, array $hw): array {
    $list = [];
    foreach ($hw['rpm'] as $key => $r) {
        unset($r['path']);
        $list[] = $r + ['label' => (string)($fans['rpm'][$key]['label'] ?? '')];
    }
    return $list;
}
