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
