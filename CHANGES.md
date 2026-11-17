###2026.11.17
- Speed test: measures the RPM of a fan from 100% down to 0%, shows where it stops and the speed it needs to start again
- Warns when a group's min speed is below the speed one of its fans needs to start
- Warns when a fan stops spinning (0 RPM) while it should run, and when another program changes a fan's speed
- Unraid notifications for problems that last more than a minute, and when they are resolved
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
