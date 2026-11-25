<?php
/*
 * PWM Fan Control - sets the fans of all groups to full speed. Only called by "rc.pwmfancontrol stop"
 * when the service hung and had to be killed, so it could not release its fans itself. A normal stop
 * releases the fans in the service (PfcController::run), and does nothing when fan control is disabled.
 */
require_once dirname(__DIR__) . '/include/common.php';
pfc_release_fans(array_keys(pfc_controlled_keys(pfc_load_config())));
