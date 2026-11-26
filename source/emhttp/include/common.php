<?php
/*
 * PWM Fan Control - shared code for the web UI, API, service and scripts.
 *
 * Concepts
 *   Fan     A PWM output on a motherboard/controller chip ("<chip>@<device>/pwmN"). Keys use the chip name
 *           instead of /sys/class/hwmon/hwmonX, because the hwmonX number can change between boots.
 *   Source  Something that reports a temperature:
 *             "array"                  hottest used, spun-up parity or data disk
 *             "pool:<name>"            the same for one pool; "unassigned" and "flash" for the others
 *             "nvidia:<GPU uuid>"      an Nvidia GPU (via nvidia-smi)
 *             "hwmon:<chip>/tempN"     any kernel temperature sensor (CPU, motherboard, NVMe, ...)
 *   Group   A set of fans following one or more sources. Every source has its own curve and the
 *           group runs at the highest speed any of its sources asks for.
 *
 * Files
 *   config.php    settings and fan groups (config.json)
 *   hardware.php  hwmon scan, fan names (fans.json), handing fans back to the BIOS, drivers, hardware check
 *   sources.php   temperature sources and fan curves
 *   service.php   starting/stopping the service (and loading drivers), fan tests in progress
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
const PFC_FANTEST    = PFC_RUN . '/fantest.json';
const PFC_STOP_FLAG  = PFC_RUN . '/fantest.stop';
const PFC_NV_CACHE   = PFC_RUN . '/nvidia.json';
const PFC_SMART_DIR  = PFC_RUN . '/smart';                // smartctl runs for disks Unraid has no temperature for yet
const PFC_HW_CACHE   = PFC_RUN . '/hardware.json';
const PFC_ALERTS     = PFC_RUN . '/alerts.json';         // warnings and the alerts sent for them
const PFC_NOTIFY_LIMITS = PFC_RUN . '/notify-limits.json'; // rate limits of service alerts
const PFC_PIDFILE    = '/var/run/' . PFC_NAME . '.pid';
const PFC_RC         = PFC_PLUGIN . '/scripts/rc.pwmfancontrol';
const PFC_HWMON      = '/sys/class/hwmon';
const PFC_NVIDIA_SMI = '/usr/bin/nvidia-smi';
const PFC_SMARTCTL   = '/usr/sbin/smartctl';
const PFC_EMHTTP     = '/var/local/emhttp';
const PFC_NOTIFY     = '/usr/local/emhttp/webGui/scripts/notify';
const PFC_UPDATE_CRON = '/usr/local/sbin/update_cron';
const PFC_CMDLINE    = '/proc/cmdline';
const PFC_MODULES    = '/sys/module';
const PFC_UPTIME     = '/proc/uptime';
const PFC_DMESG      = 'dmesg';
const PFC_MODPROBE   = 'modprobe';

const PFC_OLD_BOOT   = '/boot/config/plugins/array.fan.control';
const PFC_OLD_PLUGIN = '/usr/local/emhttp/plugins/array.fan.control';

const PFC_HISTORY_MAX   = 1440;  // samples
const PFC_HISTORY_EVERY = 60;    // seconds between samples (24 hours in total)
const PFC_FAILSAFE_PWM  = 255;
const PFC_INTERVALS     = [2, 3, 5, 10, 15, 30];

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hardware.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/service.php';

/* ------------------------------------------------------------------ helpers */

function pfc_read_int(string $file): ?int {
    $v = @file_get_contents($file);
    return ($v === false || !is_numeric(trim($v))) ? null : (int)trim($v);
}

function pfc_read_json(string $file, $default = null) {
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    return $data ?? $default;
}

/**
 * Writes a file so readers never see it half written. With $only_if_changed the write is skipped
 * when the content is the same, which saves writes to the flash drive.
 */
function pfc_write_atomic(string $file, string $content, bool $only_if_changed = false): bool {
    if ($only_if_changed && is_file($file) && @file_get_contents($file) === $content) return true;
    @mkdir(dirname($file), 0777, true);
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $content) === false) return false;
    return @rename($tmp, $file);
}

/** JSON in /tmp (status, history, ...): compact. */
function pfc_write_json(string $file, $data): bool {
    return pfc_write_atomic($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** JSON on the flash drive (settings): readable, and only written when it changed. */
function pfc_write_flash_json(string $file, $data): bool {
    return pfc_write_atomic($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", true);
}

function pfc_log(string $msg): void {
    exec('logger -t ' . escapeshellarg(PFC_NAME) . ' ' . escapeshellarg($msg));
}

/** The server name set in Settings / Identification, or the host name. */
function pfc_server_name(): string {
    $var = @parse_ini_file(PFC_EMHTTP . '/var.ini');
    $name = trim((string)($var['NAME'] ?? ''));
    return $name !== '' ? $name : (string)gethostname();
}

/**
 * Unraid notification, if enabled in the settings. $level: normal|warning|alert.
 * Problems are sent as "alert" (shown in red), resolved problems as "normal".
 * The server name is in the title and the subject (like Unraid's own notifications), so it shows on every agent:
 *   PWM Fan Control - Kaya
 *   [KAYA] PWM Fan Control: Fan problem
 */
function pfc_notify(string $subject, string $description, string $level = 'alert'): void {
    if (!is_executable(PFC_NOTIFY) || !pfc_load_config()['notify']) return;
    $server = pfc_server_name();
    $event = $server !== '' ? "PWM Fan Control - $server" : 'PWM Fan Control';
    $tag = $server !== '' ? '[' . strtoupper($server) . '] ' : '';
    exec(escapeshellarg(PFC_NOTIFY) . ' -e ' . escapeshellarg($event) . ' -s ' . escapeshellarg("{$tag}PWM Fan Control: $subject")
        . ' -d ' . escapeshellarg($description) . ' -i ' . escapeshellarg($level) . ' >/dev/null 2>&1');
}

/**
 * A notification that is sent at most once per $every seconds for the same $key, e.g. for a service
 * that keeps crashing. Used by the service and the watchdog (rc.pwmfancontrol).
 */
function pfc_notify_limited(string $key, string $subject, string $description, int $every = 3600): void {
    $limits = pfc_read_json(PFC_NOTIFY_LIMITS, []);
    if (!is_array($limits)) $limits = [];
    if (time() - (int)($limits[$key] ?? 0) < $every) return;
    $limits[$key] = time();
    pfc_write_json(PFC_NOTIFY_LIMITS, $limits);
    pfc_notify($subject, $description);
}

function pfc_pid_alive($pid): bool {
    return is_numeric($pid) && (int)$pid > 0 && is_dir('/proc/' . (int)$pid);
}

/** Seconds since the server booted (a large number when unknown). */
function pfc_uptime(): float {
    $up = explode(' ', trim((string)@file_get_contents(PFC_UPTIME)))[0] ?? '';
    return is_numeric($up) ? (float)$up : PHP_INT_MAX;
}

/** PWM value (0-255) as a percentage. */
function pfc_pct($pwm): int {
    return (int)round((int)$pwm * 100 / 255);
}

function pfc_fmt_temp($t): string {
    return $t === null ? 'n/a' : rtrim(rtrim(number_format((float)$t, 1, '.', ''), '0'), '.') . '°C';
}

/** Trims a user supplied name to plain text, max 40 characters. */
function pfc_clean_label($label): string {
    $label = trim(strip_tags((string)$label));
    return function_exists('mb_substr') ? mb_substr($label, 0, 40) : substr($label, 0, 40);
}
