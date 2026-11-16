#!/bin/bash
# Runs after the plugin files are written (on install, update and every boot)
NAME=pwm.fan.control
PLUGIN="/usr/local/emhttp/plugins/$NAME"
chmod +x "$PLUGIN/scripts/rc.pwmfancontrol"
mkdir -p "/boot/config/plugins/$NAME" "/tmp/$NAME"

# Stop a running copy from before an update, then start again if enabled
[[ -f "/var/run/$NAME.pid" ]] && "$PLUGIN/scripts/rc.pwmfancontrol" stop >/dev/null 2>&1
/usr/bin/php -r "require '$PLUGIN/include/common.php'; pfc_migrate_old(); pfc_apply_service(pfc_load_config());"

echo ""
echo "PWM Fan Control is installed."
echo "Open Settings > Utilities > PWM Fan Control to identify, name and group your fans."
echo ""
