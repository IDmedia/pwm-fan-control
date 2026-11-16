<?php
/*
 * PWM Fan Control - hands every fan we have taken over back to the BIOS.
 * Called by "rc.pwmfancontrol stop", also when the daemon was killed without cleaning up.
 */
require_once dirname(__DIR__) . '/include/common.php';
pfc_release_all();
