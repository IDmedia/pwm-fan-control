###2026.11.27
- Fixed: a disk read with smartctl while it was still spinning up gave no temperature and was then left alone for 10 minutes, which could still lead to a "no disk temperature available" alert. A read without a temperature is now tried again after 10 seconds, and a device only backs off to every 10 minutes after 3 reads in a row without a temperature

###2026.11.26b
- Disk temperatures are only read with smartctl for disk sources a group uses
- A device that gives no temperature (USB sticks, virtual media) is only tried again every 10 minutes instead of every 30 seconds

###2026.11.26
- Fixed: after disks spun up, a "Fan problem" alert (no disk temperature available) and full speed could follow 10 minutes later, because Unraid only reads disk temperatures every 30 minutes by default. Spun-up disks without a temperature are now read with smartctl (it never wakes a disk) until Unraid has their temperature

###2026.11.25
- Fixed: with fan control disabled, clicking Apply (or booting) could set fans in a group to full speed. Fans are now only touched while fan control is enabled
- When fan control is disabled, a fan is removed from its group or the plugin is removed, the fans it controlled are left at full speed instead of being handed back to the BIOS, so no fan can be stuck at a low speed. A reboot gives them back to the BIOS. The syslog lists each fan
- A fan test running on such a fan is stopped first, so it can't put back a low speed afterwards
- A service that hangs when stopped is killed after 10 seconds, and the fans of all groups are set to full speed

###2026.11.23
- Notifications show which server they come from: the title is "PWM Fan Control - <server>" and the subject starts with "[<SERVER>]", like Unraid's own notifications
- Fixed: when spun-down disks spin up, Unraid can take several minutes to show their temperature. This no longer counts as a fan problem: for up to 10 minutes the fans run at the lowest speed of the curve (at least the spun-down speed), then at full speed with an alert as before
- "Problem resolved" notifications say the problem is gone instead of only repeating its text

###2026.11.18
- The group editor only offers named fans (and the fans already in the group). Unnamed outputs, usually empty headers, are behind a "Show unnamed outputs" link
- A fan that gets a name shows up in the group editor right away

###2026.11.17b
- Disk temperatures are now separate sources: Array (parity and data disks), one per pool, Unassigned disks and Flash, so HDD, SSD and NVMe pools can have their own curves
- Existing groups get the Array source and a source for every pool that was monitored, with the curve of the former Array disks source, so nothing changes until you edit the curves
- The "Disk types to monitor" setting is gone: add the disk sources you want to a group instead. Single disks can still be unticked
- Identify without a paired RPM sensor ignores other fans on the same chip that change speed during the test

###2026.11.17a
- Fixed: fans at full speed on nct6775 chips were reported as "changed by something else" (the driver shows manual mode at 100% as mode 0)
- When a fan's speed keeps being changed by something else, the syslog says what was set and found, and whether the mode changed (BIOS) or only the speed (another program)

###2026.11.17
- Requires Unraid 7.4 or newer
- Fan icon from Unraid's own icon set instead of the snowflake
- Speed changes are no longer logged to syslog by default, problems still are
- Speed test: steps a fan down from 100% until it stops, then finds the speed it needs to start again; the fan stands still only for a few seconds at a time
- Fan tests don't start, and stop right away, when the fan's group needs it (temperature at or above high, source unreadable, parity check)
- Identify slows fans to 20% by default instead of stopping them, and keeps them slow until they have stopped slowing down (8 seconds per step by default)
- Warns when a group's min speed is below the speed one of its fans needs to start
- Warns when a fan stops spinning (0 RPM) while it should run, and when another program changes a fan's speed
- Problems are sent as Unraid alert notifications when they last more than a minute, a crash or watchdog restart right away, and a notice when resolved
- Notifications don't flood: one alert per problem, resolved only after 2 minutes without it, the same problem at most every 4 hours, no repeat after a service restart, crash and watchdog alerts at most once an hour
- A temperature source that can't be read or is gone (e.g. Nvidia driver missing after an update) now shows a warning and sends an alert; before, its fans silently ran at full speed
- A stopped array counts as spun down instead of unreadable
- No alerts in the first 5 minutes after boot, while drivers load and the array starts
- Warns when a fan runs much faster or slower than its speed test measured, which usually means fans were moved to other headers
- Missing fans say why: the chip is gone (driver not loaded, ACPI conflict) or only that output (hardware or BIOS changed)
- Hardware check on the settings page: fan driver, fan outputs, ACPI resource conflicts and whether acpi_enforce_resources=lax is needed
- New setting "Drivers to load": loads the fan controller driver (with options) at boot
- Points out fans in a group without a paired RPM sensor, which can't be watched
- A single failed temperature reading no longer sends fans to full speed; two in a row still do
- An error in the service, or a crash, now runs the fans at full speed instead of leaving them at their last speed
- Disk temperature sensors of the kernel (drivetemp) are no longer read, so disks can spin down
- Dashboard tile uses a lighter request and shows warnings
- Shows when a fan header is in DC mode
- Settings and fan names are only written to the flash drive when they change

###2026.11.16
- First release of PWM Fan Control, replacing the unreleased Array Fan Control plugin
- Fan groups: every group follows one or more temperature sources, the highest speed wins
- Temperature sources: array disks, Nvidia GPUs (nvidia-smi) and kernel sensors (CPU, motherboard, NVMe)
- Background service checking every 2-30 seconds, with a watchdog and a slow-down delay
- Runs at full speed when a temperature can't be read
- Identify fans from the web UI with live RPM readings, name fans and sensors
- Fan curve and 24 hour history charts per group, dashboard tile
- Imports settings from Array Fan Control
