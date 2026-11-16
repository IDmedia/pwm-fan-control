###2026.11.16
- First release of PWM Fan Control, replacing the unreleased Array Fan Control plugin
- Fan groups: every group follows one or more temperature sources, the highest speed wins
- Temperature sources: array disks, Nvidia GPUs (nvidia-smi) and kernel sensors (CPU, motherboard, NVMe)
- Background service checking every 2-30 seconds, with a watchdog and a slow-down delay
- Runs at full speed when a temperature can't be read
- Identify fans from the web UI with live RPM readings, name fans and sensors
- Fan curve and 24 hour history charts per group, dashboard tile
- Imports settings from Array Fan Control
