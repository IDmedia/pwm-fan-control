<?php
/*
 * PWM Fan Control - AJAX endpoint for the settings page and dashboard tile.
 * GET  ?action=state[&history=1]          live data
 * POST action=save_config, config=<json>  settings and fan groups
 * POST action=save_fan                    name / paired RPM sensor of a PWM output
 * POST action=save_rpm_label              name of an RPM sensor
 * POST action=save_source_label           name of a temperature source
 * POST action=identify, key=...           identify a PWM output
 * POST action=identify_stop               stop identifying and restore the fan
 * (POST requests are protected by Unraid's csrf_token check.)
 */
require_once __DIR__ . '/common.php';

header('Content-Type: application/json');

function pfc_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pfc_fail(string $msg, int $code = 400): void {
    pfc_reply(['ok' => false, 'error' => $msg], $code);
}

$migrated = pfc_migrate_old();
$action = $_POST['action'] ?? $_GET['action'] ?? 'state';

switch ($action) {

case 'state':
    $cfg = pfc_load_config();
    $fans = pfc_load_fans();
    $hw = pfc_scan_hwmon();
    $disks = pfc_read_disks($cfg);
    $nv = pfc_nvidia();
    $sources = pfc_read_sources($cfg, $hw, $fans, $disks, $nv);
    $owner = pfc_controlled_keys($cfg);

    $pwms = [];
    foreach ($hw['pwm'] as $key => $p) {
        $f = $fans['pwm'][$key] ?? [];
        $rpm_key = $f['rpm'] ?? $p['guess_rpm'];
        $p['label'] = (string)($f['label'] ?? '');
        $p['group'] = $owner[$key] ?? null;
        $p['rpm_key'] = $rpm_key;
        $p['rpm_confirmed'] = isset($f['rpm']);
        $p['rpm'] = $rpm_key !== null ? ($hw['rpm'][$rpm_key]['rpm'] ?? null) : null;
        $p['enable_name'] = pfc_enable_mode_name($p['enable']);
        unset($p['path']);
        $pwms[] = $p;
    }
    $rpms = [];
    foreach ($hw['rpm'] as $key => $r) {
        $r['label'] = (string)($fans['rpm'][$key]['label'] ?? '');
        unset($r['path']);
        $rpms[] = $r;
    }
    // Groups as they would run right now, without the slow-down delay
    $preview = [];
    foreach ($cfg['groups'] as $g) $preview[$g['id']] = pfc_group_demand($g, $sources);

    pfc_reply([
        'ok'         => true,
        'time'       => time(),
        'config'     => $cfg,
        'errors'     => pfc_validate_config($cfg),
        'running'    => pfc_daemon_pid() !== null,
        'pwm'        => $pwms,
        'rpm'        => $rpms,
        'sources'    => array_values($sources),
        'disks'      => $disks,
        'nvidia'     => ['available' => $nv['available'], 'error' => $nv['error']],
        'preview'    => $preview,
        'status'     => pfc_read_json(PFC_STATUS),
        'history'    => isset($_GET['history']) ? pfc_read_json(PFC_HISTORY, []) : null,
        'identify'   => pfc_read_json(PFC_IDENTIFY),
        'old_plugin' => is_dir(PFC_OLD_PLUGIN),
        'migrated'   => $migrated,
    ]);

case 'save_config':
    $raw = json_decode((string)($_POST['config'] ?? ''), true);
    if (!is_array($raw)) pfc_fail('Invalid data.');
    $cfg = pfc_normalize_config($raw);
    $errors = pfc_validate_config($cfg);
    if ($errors) pfc_fail(implode("\n", $errors));
    if ($cfg['enabled'] && is_dir(PFC_OLD_PLUGIN)) {
        pfc_fail('Remove the old "Array Fan Control" plugin first, otherwise both plugins will fight over the fans.');
    }
    // Only known fans can be added to a group
    $hw = pfc_scan_hwmon();
    $old = pfc_load_config();
    $known = pfc_controlled_keys($old);
    foreach ($cfg['groups'] as $g) foreach ($g['fans'] as $key) {
        if (!isset($hw['pwm'][$key]) && !isset($known[$key])) pfc_fail("Unknown fan: $key");
    }
    pfc_save_config($cfg);
    pfc_apply_service($cfg);
    pfc_reply(['ok' => true, 'running' => pfc_daemon_pid() !== null]);

case 'save_fan':
    $key = (string)($_POST['key'] ?? '');
    $hw = pfc_scan_hwmon();
    $fans = pfc_load_fans();
    if (!isset($hw['pwm'][$key]) && !isset($fans['pwm'][$key])) pfc_fail('Unknown fan.');
    $f = $fans['pwm'][$key] ?? [];
    if (isset($_POST['label'])) $f['label'] = pfc_clean_label($_POST['label']);
    if (isset($_POST['rpm'])) {
        $rpm = (string)$_POST['rpm'];
        if ($rpm === '') unset($f['rpm']);
        elseif ($rpm === 'none' || isset($hw['rpm'][$rpm])) $f['rpm'] = $rpm;
        else pfc_fail('Unknown RPM sensor.');
    }
    $fans['pwm'][$key] = $f;
    pfc_save_fans($fans);
    pfc_reply(['ok' => true]);

case 'save_rpm_label':
case 'save_source_label':
    $key = (string)($_POST['key'] ?? '');
    $fans = pfc_load_fans();
    if ($action === 'save_rpm_label') {
        if (!isset(pfc_scan_hwmon()['rpm'][$key])) pfc_fail('Unknown RPM sensor.');
        $section = 'rpm';
    } else {
        if (!preg_match('/^(array|nvidia:GPU-[\w-]+|hwmon:[^\s]+\/temp\d+)$/', $key)) pfc_fail('Unknown temperature source.');
        $section = 'temp';
    }
    $label = pfc_clean_label($_POST['label'] ?? '');
    if ($label === '') unset($fans[$section][$key]);
    else $fans[$section][$key] = ['label' => $label];
    pfc_save_fans($fans);
    pfc_reply(['ok' => true]);

case 'identify':
    $key = (string)($_POST['key'] ?? '');
    $hw = pfc_scan_hwmon();
    if (!isset($hw['pwm'][$key])) pfc_fail('Unknown fan.');
    if (!$hw['pwm'][$key]['writable']) pfc_fail('This PWM output is not writable.');
    $running = pfc_identify_running();
    $pending = pfc_read_json(PFC_IDENTIFY);
    if ($running || (($pending['phase'] ?? '') === 'starting' && time() - ($pending['time'] ?? 0) < 5)) {
        pfc_fail('Another fan is being identified right now.');
    }
    @mkdir(PFC_RUN, 0777, true);
    @unlink(PFC_STOP_FLAG);
    pfc_write_json(PFC_IDENTIFY, ['key' => $key, 'phase' => 'starting', 'pid' => 0, 'time' => time()]);
    exec('setsid nohup /usr/bin/php ' . escapeshellarg(PFC_PLUGIN . '/scripts/identify.php') . ' ' . escapeshellarg($key) . ' >/dev/null 2>&1 < /dev/null &');
    pfc_reply(['ok' => true]);

case 'identify_stop':
    @mkdir(PFC_RUN, 0777, true);
    touch(PFC_STOP_FLAG);
    pfc_reply(['ok' => true]);

default:
    pfc_fail('Unknown action.');
}
