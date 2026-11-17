<?php
/*
 * PWM Fan Control - background service. Started and stopped by rc.pwmfancontrol.
 * The control loop itself is in include/controller.php.
 */
require_once dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/controller.php';

@mkdir(PFC_RUN, 0777, true);
$lock = fopen(PFC_RUN . '/daemon.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "PWM Fan Control is already running\n");
    exit(0);
}
file_put_contents(PFC_PIDFILE, (string)getmypid());

$controller = new PfcController();
$finished = false;

// A crash (fatal PHP error) must not leave the fans at a low speed: run them at full speed
// until the watchdog has restarted the service.
register_shutdown_function(function () use ($controller, &$finished) {
    if ($finished) return;
    $error = error_get_last();
    $msg = 'The service crashed, fans run at full speed until the watchdog restarts it (within a minute)' . ($error ? ': ' . $error['message'] : '.');
    pfc_log($msg);
    $controller->failsafe();
    pfc_notify('Service crashed', $msg);
});

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use ($controller) { $controller->running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGHUP, SIG_IGN);
}

$controller->run();
$finished = true;
if ((int)@file_get_contents(PFC_PIDFILE) === getmypid()) @unlink(PFC_PIDFILE);
