<?php
/*
 * PWM Fan Control - AJAX endpoint for the settings page and dashboard tile.
 * GET  ?action=state[&history=1]          everything the settings page shows
 * GET  ?action=tile                       what the dashboard tile shows (cheap: no nvidia-smi, no disks)
 * POST action=save_config, config=<json>  settings and fan groups
 * POST action=save_fan                    name / paired RPM sensor of a PWM output
 * POST action=save_rpm_label              name of an RPM sensor
 * POST action=save_source_label           name of a temperature source
 * POST action=fantest, key=..., mode=identify|speed
 * POST action=fantest_stop                stop the test and restore the fan
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

/** The service status, only while the service is actually running. */
function pfc_live_status(): ?array {
    $pid = pfc_daemon_pid();
    $status = $pid ? pfc_read_json(PFC_STATUS) : null;
    return is_array($status) ? $status : null;
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

    // Groups as they would run right now, without the slow-down delay
    $preview = [];
    foreach ($cfg['groups'] as $g) $preview[$g['id']] = pfc_group_demand($g, $sources);

    pfc_reply([
        'ok'         => true,
        'time'       => time(),
        'config'     => $cfg,
        'errors'     => pfc_validate_config($cfg),
        'running'    => pfc_daemon_pid() !== null,
        'status'     => pfc_live_status(),
        'pwm'        => pfc_fan_list($cfg, $fans, $hw),
        'rpm'        => pfc_rpm_list($fans, $hw),
        'sources'    => array_values($sources),
        'disks'      => $disks,
        'nvidia'     => ['available' => $nv['available'], 'error' => $nv['error']],
        'preview'    => $preview,
        'history'    => isset($_GET['history']) ? pfc_read_json(PFC_HISTORY, []) : null,
        'fantest'    => pfc_read_json(PFC_FANTEST),
        'old_plugin' => is_dir(PFC_OLD_PLUGIN),
        'migrated'   => $migrated,
    ]);

case 'tile':
    $cfg = pfc_load_config();
    $fans = pfc_load_fans();
    $hw = pfc_scan_hwmon();
    pfc_reply([
        'ok'      => true,
        'enabled' => $cfg['enabled'],
        'groups'  => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name']], $cfg['groups']),
        'status'  => pfc_live_status(),
        'pwm'     => array_map(fn($p) => array_intersect_key($p, array_flip(['key', 'label', 'rpm_key', 'value', 'group'])), pfc_fan_list($cfg, $fans, $hw)),
        'rpm'     => pfc_rpm_list($fans, $hw),
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
    // Only known fans can be added to a group (fans already in a group may be missing right now)
    $hw = pfc_scan_hwmon();
    $known = pfc_controlled_keys(pfc_load_config());
    foreach ($cfg['groups'] as $g) foreach ($g['fans'] as $key) {
        if (!isset($hw['pwm'][$key]) && !isset($known[$key])) pfc_fail("Unknown fan: $key");
    }
    if (!pfc_save_config($cfg)) pfc_fail('Could not write the settings to the flash drive.', 500);
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
        if ($rpm !== '' && $rpm !== 'none' && !isset($hw['rpm'][$rpm])) pfc_fail('Unknown RPM sensor.');
        if ($rpm === '') unset($f['rpm']);
        else $f['rpm'] = $rpm;
        // A speed test measured with another sensor no longer applies
        if (isset($f['test']['rpm_key']) && $f['test']['rpm_key'] !== $rpm) unset($f['test']);
    }
    $fans['pwm'][$key] = $f;
    if (!pfc_save_fans($fans)) pfc_fail('Could not write to the flash drive.', 500);
    pfc_reply(['ok' => true]);

case 'save_rpm_label':
case 'save_source_label':
    $key = (string)($_POST['key'] ?? '');
    $fans = pfc_load_fans();
    if ($action === 'save_rpm_label') {
        if (!isset(pfc_scan_hwmon()['rpm'][$key])) pfc_fail('Unknown RPM sensor.');
        $section = 'rpm';
    } else {
        if (!preg_match('/^(array|nvidia:GPU-[\w-]+|hwmon:\S+\/temp\d+)$/', $key)) pfc_fail('Unknown temperature source.');
        $section = 'temp';
    }
    $label = pfc_clean_label($_POST['label'] ?? '');
    if ($label === '') unset($fans[$section][$key]);
    else $fans[$section][$key] = ['label' => $label];
    if (!pfc_save_fans($fans)) pfc_fail('Could not write to the flash drive.', 500);
    pfc_reply(['ok' => true]);

case 'fantest':
    $key = (string)($_POST['key'] ?? '');
    $mode = (string)($_POST['mode'] ?? 'identify');
    if (!in_array($mode, ['identify', 'speed'], true)) pfc_fail('Unknown test.');
    $hw = pfc_scan_hwmon();
    if (!isset($hw['pwm'][$key])) pfc_fail('Unknown fan.');
    if (!$hw['pwm'][$key]['writable']) pfc_fail('This PWM output is not writable.');
    $error = pfc_fantest_start($key, $mode);
    if ($error) pfc_fail($error);
    pfc_reply(['ok' => true]);

case 'fantest_stop':
    @mkdir(PFC_RUN, 0777, true);
    touch(PFC_STOP_FLAG);
    pfc_reply(['ok' => true]);

default:
    pfc_fail('Unknown action.');
}
