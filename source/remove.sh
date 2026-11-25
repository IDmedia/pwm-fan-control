#!/bin/bash
# Runs when the plugin is removed. Settings in /boot/config/plugins/pwm.fan.control are kept.
NAME=pwm.fan.control
PLUGIN="/usr/local/emhttp/plugins/$NAME"
[[ -x "$PLUGIN/scripts/rc.pwmfancontrol" ]] && "$PLUGIN/scripts/rc.pwmfancontrol" stop
rm -f "/boot/config/plugins/$NAME/$NAME.cron"
/usr/local/sbin/update_cron
rm -rf "$PLUGIN" "/tmp/$NAME" "/var/run/$NAME.pid"
echo "PWM Fan Control removed. The fans it controlled run at full speed until the next reboot, then the BIOS controls them again."
