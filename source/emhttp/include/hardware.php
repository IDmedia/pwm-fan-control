<?php
/*
 * PWM Fan Control - fans and kernel sensors (/sys/class/hwmon), fan names (fans.json), fan drivers and
 * the hardware check.
 *
 * fans.json on the flash drive:
 * {
 *   "pwm":  { "<fan key>":  { "label": "...", "rpm": "<rpm key>|none", "test": {...} } },
 *   "rpm":  { "<rpm key>":  { "label": "..." } },
 *   "temp": { "<source id>": { "label": "..." } }
 * }
 *   rpm          RPM sensor paired with the output, set by Identify, the speed test or by hand
 *   test         result of the last speed test, see scripts/fantest.php
 */

function pfc_load_fans(): array {
    $data = pfc_read_json(PFC_FANS, []);
    if (!is_array($data)) $data = [];
    foreach (['pwm', 'rpm', 'temp'] as $k) $data[$k] = is_array($data[$k] ?? null) ? $data[$k] : [];
    // Versions before 2026.11.25 kept the BIOS mode of each fan here, it is no longer used
    foreach ($data['pwm'] as &$f) if (is_array($f)) unset($f['orig_enable']);
    unset($f);
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

        // Disk temperatures come from Unraid (the disk sources). Reading drivetemp sensors directly
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

/* ------------------------------------------------------- drivers and setup */

// Drivers of motherboard/controller chips that offer PWM fan outputs
const PFC_FAN_DRIVERS = ['nct6775', 'nct6683', 'nct6687', 'it87', 'f71882fg', 'f71805f', 'w83627ehf', 'w83627hf', 'w83795',
    'sch5627', 'sch5636', 'asus_ec_sensors', 'dell_smm_hwmon', 'thinkpad_acpi', 'applesmc', 'pwm_fan', 'emc2305', 'max6650'];

/** Loads the kernel modules from the "drivers" setting that aren't loaded yet. Returns the errors. */
function pfc_load_drivers(array $cfg): array {
    $errors = [];
    foreach (pfc_driver_list($cfg) ?? [] as $module => $options) {
        if (is_dir(PFC_MODULES . '/' . str_replace('-', '_', $module))) continue;
        exec(PFC_MODPROBE . ' ' . implode(' ', array_map('escapeshellarg', array_merge([$module], $options))) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $errors[] = "Could not load driver $module" . ($out ? ': ' . trim(implode(' ', $out)) : '');
            pfc_log(end($errors));
        } else {
            pfc_log("Loaded driver $module");
        }
        $out = [];
    }
    if (!$errors) @unlink(PFC_HW_CACHE);
    return $errors;
}

/**
 * Checks what is needed to control fans, for the hardware check in the web UI and for warnings:
 *   drivers      loaded fan controller drivers
 *   lax          acpi_enforce_resources=lax is on the kernel command line
 *   conflicts    kernel log lines about ACPI resource conflicts (the reason "lax" is needed)
 *   pwm/writable number of PWM outputs found / writable
 * The kernel log part is cached for a minute, it can be large.
 */
function pfc_hardware_check(?array $hw = null): array {
    $hw = $hw ?? pfc_scan_hwmon();
    $cache = pfc_read_json(PFC_HW_CACHE);
    if (!is_array($cache) || time() - ($cache['time'] ?? 0) > 60) {
        exec(PFC_DMESG . ' 2>/dev/null', $log);
        $conflicts = [];
        foreach ($log as $line) {
            if (preg_match('/conflicts with OpRegion|resource conflict|acpi_enforce_resources/i', $line)) $conflicts[] = trim($line);
        }
        $cache = ['time' => time(), 'conflicts' => array_slice(array_values(array_unique($conflicts)), -6)];
        pfc_write_json(PFC_HW_CACHE, $cache);
    }
    return [
        'drivers'   => array_values(array_filter(PFC_FAN_DRIVERS, fn($m) => is_dir(PFC_MODULES . "/$m"))),
        'lax'       => (bool)preg_match('/(^|\s)acpi_enforce_resources=lax(\s|$)/', (string)@file_get_contents(PFC_CMDLINE)),
        'conflicts' => $cache['conflicts'],
        'pwm'       => count($hw['pwm']),
        'writable'  => count(array_filter($hw['pwm'], fn($p) => $p['writable'])),
    ];
}

/**
 * What to do when fans can't be found or controlled, based on pfc_hardware_check(). Null if all looks fine.
 * $missing: a fan in a group is missing although other outputs work (its chip may be the one in conflict).
 */
function pfc_hardware_hint(array $check, bool $missing = false): ?string {
    if ($check['conflicts'] && !$check['lax'] && ($missing || !$check['writable'])) {
        return 'The kernel log shows an ACPI resource conflict, which keeps the fan driver from using the chip. '
            . 'Add acpi_enforce_resources=lax to the boot options (Main > Flash > Syslinux Configuration, on the "append" line) and reboot.';
    }
    if (!$check['drivers']) {
        return 'No fan controller driver is loaded. Enter it under "Drivers to load" in the settings (e.g. nct6775 or it87), '
            . 'or let the Dynamix System Temperature plugin load it.';
    }
    if (!$check['pwm']) {
        return 'The driver ' . implode(', ', $check['drivers']) . ' is loaded but offers no fan outputs. '
            . ($check['lax'] ? 'Your board may need a different driver.' : 'Your board may need acpi_enforce_resources=lax in the boot options, or a different driver.');
    }
    if (!$check['writable']) return 'Fan outputs were found but none can be written. The plugin has to run as root.';
    return null;
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
 * The RPM a speed test measured at $pwm, interpolated between its steps. Null below the lowest
 * speed the fan kept running at, where it may be stopped.
 */
function pfc_expected_rpm(array $points, int $pwm): ?float {
    $running = array_values(array_filter($points, fn($p) => is_array($p) && (int)($p[1] ?? 0) > 0));
    if (!$running) return null;
    usort($running, fn($a, $b) => $a[0] <=> $b[0]);
    if ($pwm < $running[0][0]) return null;
    $prev = $running[0];
    foreach ($running as $p) {
        if ($pwm <= $p[0]) {
            return $p[0] === $prev[0] ? (float)$p[1] : $prev[1] + ($p[1] - $prev[1]) * ($pwm - $prev[0]) / ($p[0] - $prev[0]);
        }
        $prev = $p;
    }
    return (float)$prev[1];
}

/**
 * Whether an output in mode $enable is under manual control at speed $pwm. The nct6775 driver reports
 * manual mode at 255 as mode 0 ("full speed"), so that counts as manual too.
 */
function pfc_is_manual(?int $enable, int $pwm): bool {
    return $enable === null || $enable === 1 || ($enable === 0 && $pwm === 255);
}

/** Switches an output to manual control and sets its speed. Returns null on success, or an error message. */
function pfc_drive_fan(array $fan, int $pwm): ?string {
    if (!pfc_is_manual($fan['enable'], $pwm)) {
        if (@file_put_contents("{$fan['path']}_enable", '1') === false) return 'could not switch it to manual control';
    }
    if ($fan['value'] !== $pwm && @file_put_contents($fan['path'], (string)$pwm) === false) return 'could not set its speed';
    return null;
}

/**
 * Releases fans the plugin stops controlling (fan control disabled, fan removed from its group, plugin
 * removed): they are left in manual mode at full speed, so no fan is ever left at a low speed with nothing
 * controlling it. A reboot gives them back to the BIOS. Only called for fans the service actually drove,
 * so nothing is touched while fan control is disabled. A fan test running on one of them is stopped
 * first, otherwise it would put back the speed it found (ours) when it ends. Logs every fan.
 */
function pfc_release_fans(array $keys): void {
    if (!$keys) return;
    $test = pfc_fantest_running();
    if ($test && in_array($test['key'] ?? '', $keys, true)) pfc_fantest_stop(5);
    $hw = pfc_scan_hwmon();
    $fans = pfc_load_fans();
    foreach (array_unique($keys) as $key) {
        $label = (string)($fans['pwm'][$key]['label'] ?? '');
        $name = '"' . ($label !== '' ? $label : $key) . '"';
        $fan = $hw['pwm'][$key] ?? null;
        $error = $fan ? pfc_drive_fan(['value' => null] + $fan, PFC_FAILSAFE_PWM) : 'not found'; // value null: always write it
        pfc_log($error ? "Fan $name could not be set to full speed ($error). Check this fan!" : "Fan $name released at full speed");
    }
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
