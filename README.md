<div align="center">
  <img src="docs/logo.png" width="200" alt="PWM Fan Control logo">
</div>

# PWM Fan Control

An Unraid plugin that controls the PWM fan headers on your motherboard based on temperatures: array disks, Nvidia
GPUs, the CPU, NVMe drives or any other sensor the kernel reports.

Fans are organized into groups. Each group follows one or more temperature sources, every source has its own fan
curve, and the group runs at the highest speed any of its sources asks for. A typical setup has an *Array fans*
group following the disks, and a *GPU fans* group following the GPUs and the CPU. The settings page helps you
find out which header drives which fan, lets you name them, and shows the fan curves and the last 24 hours of
temperatures and fan speeds.

Problems? Run `grep pwm.fan.control /var/log/syslog` and include the output in your report.

## Requirements

- Unraid 7.4 or newer.
- A motherboard sensor chip whose driver exposes PWM outputs, for example `nct6775` or `it87`. Enter the driver
  under *Drivers to load* in the plugin settings so it is loaded at every boot, or let the **Dynamix System
  Temperature** plugin detect and load it.
- `acpi_enforce_resources=lax` on the boot line (*Main > Flash > Syslinux Configuration*), **only if** the kernel log
  shows an ACPI resource conflict for the chip. Then the BIOS claims the chip, and the driver can't load or offers no
  fan outputs, so no PWM value can be written. The *Hardware check* at the bottom of the plugin page shows whether
  this is the case. Many boards don't need it (newer kernels can use the board's own interface, for example on
  some ASUS boards).

  ```
  append initrd=/bzroot acpi_enforce_resources=lax
  ```

- The fan headers you want to control set to **PWM** mode in the BIOS.
- For GPU temperatures: an Nvidia driver that provides `nvidia-smi`, such as the **Nvidia Driver** plugin or the
  **CMP 170HX Unlock Driver** plugin.

## Install

1. Remove or disable any other fan control first (see below).
2. In Unraid, go to *Plugins > Install Plugin* and paste:

   ```
   https://raw.githubusercontent.com/IDmedia/pwm-fan-control/main/pwm.fan.control.plg
   ```

3. Open *Settings > Utilities > PWM Fan Control*.
4. Identify and name your fans, and create your fan groups (see *Setting up*).
5. Set *Enable fan control* to *Yes* and click *Apply*.

Fan control is off after installing. Until you enable it, the plugin only reads sensors and never changes a fan.

## Conflict with other fan control

A fan can only have one controller. If two programs write to the same PWM output, they fight, and the fan keeps
jumping between their speeds.

- Remove or disable the **Dynamix Auto Fan Control** plugin, fan control User Scripts (such as
  `fan_speed_control.sh`) and anything else that writes to `/sys/class/hwmon/*/pwm*`. The plugin can't tell what they
  are, but it shows a warning when the speed of one of its fans keeps being changed by something else.
- Enabling fan control is refused while the earlier **Array Fan Control** plugin is installed. Its settings are
  imported as a group called *Array fans* the first time the plugin starts.
- Fans that aren't in any group are never touched, so the BIOS keeps controlling those (for example the CPU fan).

## Setting up

1. **Identify your fans.** Under *Fans*, click *Identify* next to an output. The fan connected to it slows down
   (to 20% by default, so it doesn't stop), then runs at full speed, then goes back to how it was. The page shows the live RPM of every
   fan sensor on that chip, so you can see, hear and read which fan it is. The RPM sensor that reacts is paired
   with the output automatically.
2. **Name them**, for example *GPU intake* or *Front top*. Fan sensors without speed control, like a pump, can be
   named under *Other fan sensors*. Temperature sources can be named too, for example *Top GPU*.
3. **Run a speed test** (optional, about a minute per fan). The fan steps down from 100% until it stops, while the
   page records its RPM, then the test finds the speed it needs to start again, trying from the lowest speed it kept
   running at upwards. The result shows both speeds. The fan stands still only for a few seconds at a time.

   During *Identify* and the speed test, the fan doesn't follow its group. So a test doesn't start, and stops right
   away and restores the fan, when the group needs the fan: one of its temperatures is at or above its *high*
   setting, a source can't be read, or a parity check runs. Fans in no group aren't checked, so test those only
   when nothing they cool is hot.
4. **Create groups.** Under *Fan groups*, click *Add group*, tick the fans that belong to it and add one or more
   temperature sources. A fan can only be in one group.
5. **Adjust the curves.** The chart next to each group updates while you type, and a dot shows where each source
   is right now. If a group's min speed is below the speed one of its fans needs to start (from the speed test),
   the group shows a warning: below that speed the fan may stop and not start again until the speed goes up.

## What it does

A background service checks every few seconds (5 by default) and for every group:

1. Reads the temperature of each of the group's sources.
2. Works out the speed each source asks for from its curve:
   - at or below **Low °C**: the **Min speed**
   - at or above **High °C**: the **Max speed**
   - in between: a speed on the straight line between those two points
3. Takes the **highest** of those speeds. A cold array never slows the fans down while a GPU is hot, and the other
   way round.
4. Applies it. Fans **speed up right away** but only **slow down** once the lower speed has been needed for the
   group's *slow-down delay*. Until then they keep the highest speed asked for within the delay, so fans don't
   pulse when a GPU load starts and stops.

Example *GPU fans* group with a 30 second slow-down delay:

| Source | Low | High | Min speed | Max speed |
|---|---|---|---|---|
| GPU 0 | 45 °C | 75 °C | 35% | 100% |
| GPU 1 | 45 °C | 75 °C | 35% | 100% |
| CPU (`k10temp` Tctl) | 60 °C | 85 °C | 25% | 100% |

GPU 0 at 60 °C asks for 67%, GPU 1 at 50 °C for 46% and the CPU at 55 °C for 25%, so the fans run at 67%. If GPU 0
jumps to 80 °C, the fans go to 100% within one check. When it cools down again, they stay at 100% for 30 more
seconds before following the curve back down.

The first time the plugin controls a fan, it remembers the fan's BIOS mode and switches the output to manual
control. Disabling fan control, removing a fan from its group or removing the plugin puts that mode back.

### Temperature sources

| Source | Temperature | Notes |
|---|---|---|
| Array disks | Hottest monitored disk that is spun up | When all monitored disks are spun down, it asks for its *All disks spun down* speed. During a parity check or rebuild, it asks for its max speed (can be turned off). |
| Nvidia GPU | `nvidia-smi`, one source per GPU | GPUs are identified by their UUID, so the sources stay correct if the cards are renumbered. |
| Kernel sensors | Everything under `/sys/class/hwmon` | CPU (`k10temp` Tctl, `coretemp`), motherboard, NVMe (`nvme` Composite) and more. `drivetemp` sensors are left out: reading them sends commands to the disks, which can keep them from spinning down. Use the array disks source for disks. |

Fans and kernel sensors are stored by chip name and device instead of `hwmonN`, so the setup keeps working when the
hwmon numbers change after a reboot or a kernel update.

Names, RPM pairings and groups belong to the **header**, not to the fan. If you move fans to other headers, the
plugin keeps controlling the headers as before, so the fans now follow the wrong temperatures. After a speed test,
the plugin notices this when a fan runs much faster or slower than it measured (most fan models differ a lot), and a
header that is now empty shows up as a stopped fan. Two identical fans swapped with each other can't be detected, so
run *Identify* after rewiring.

### When something goes wrong

A failure makes fans run faster, never slower.

| Situation | What happens |
|---|---|
| A source can't be read once (a sensor glitch) | Its previous reading is used for that one check. |
| A source can't be read on two checks in a row (sensor missing, `nvidia-smi` fails, no disk temperature) | The groups using it run at full speed. A warning is shown on the page and logged. |
| `nvidia-smi` hangs, for example after a GPU or driver crash | `nvidia-smi` runs in the background and is never waited on, and a run is stopped after 5 seconds. If no fresh reading arrives for 20 seconds (or 3 checks, whichever is longer), the GPU counts as unreadable. Groups that don't use the GPU keep working normally. |
| The Nvidia driver is still loading at boot | Groups that follow a GPU run at full speed until it is ready. No alerts are sent in the first 5 minutes after boot. |
| A GPU or sensor is gone (Nvidia driver missing after an Unraid update, sensor driver not loaded) | The groups using it run at full speed, with a warning and an alert saying which source is missing. |
| The array is stopped (for example an encrypted array waiting for its passphrase) | The array disks source counts as spun down: its groups run at their spun-down speed, without a warning. |
| An error inside the service | Every controlled fan runs at full speed, the error is logged, and the next check tries again. |
| The service crashes or freezes | A crash sets every controlled fan to full speed and sends an alert right away. A watchdog checks every minute and restarts the service, and sends an alert when it had to. Crash and watchdog alerts together are sent at most once an hour. A service that hasn't updated its status for 90 seconds counts as frozen. |
| A fan in a group disappears | Warning, with the likely reason: the chip is gone (driver not loaded, ACPI conflict, see the *Hardware check*) or the chip is there but not this output (hardware or BIOS changed). The other fans keep working. |
| A fan reports 0 RPM for 20 seconds while it should spin | Warning: the fan or its cable failed, or the fan was moved to another header. Needs a paired RPM sensor (from *Identify*, the speed test or picked by hand). Without a speed test, a fan counts as "should spin" from 30%. |
| A fan runs below 65% or above 150% of the RPM its speed test measured at the same speed, for a minute | Warning: a different fan is probably connected to this header now. Needs a paired RPM sensor and a speed test. |
| Something else keeps changing the speed or mode of a fan | Warning: another fan control is probably running. |

Warnings are shown on the settings page and the dashboard tile right away, and logged. A warning that lasts more
than a minute also sends an Unraid **alert** notification (red), and a normal notification when it is resolved
(*Send notifications*). Configure in *Settings > Notification Settings* where alerts go (browser, email, Telegram,
...). Notifications are kept quiet on purpose:

- A problem that can't be fixed sends one alert, not one per check.
- A problem only counts as resolved once it has been gone for 2 minutes, so a problem that comes and goes stays one
  problem.
- The same problem is alerted at most once every 4 hours, also when it was resolved in between.
- No alerts in the first 5 minutes after boot, while drivers load and the array starts. A problem that is still
  there after that is alerted.
- Restarting the service (or saving settings) doesn't alert again. After a reboot, a problem that still exists is
  alerted once more.

The page also points out fans in a group that have no paired RPM sensor, because those can't be watched.

## Settings

Per group:

| Setting | Meaning |
|---|---|
| Name | Shown on the page, the dashboard tile and in log messages. |
| Fans | The PWM outputs the group controls. A fan can only be in one group. |
| Slow-down delay | Seconds a lower speed has to be needed before the fans slow down. 0-600, default 30. |
| Temperature sources | One or more sources, each with *Low °C*, *High °C*, *Min speed* and *Max speed*. The array disks source also has *All disks spun down*. |

General:

| Setting | Meaning |
|---|---|
| Enable fan control | While *No*, the plugin never changes a fan. |
| Check temperatures every | 2, 3, 5, 10, 15 or 30 seconds. Default 5. |
| Log speed changes to syslog | Logs changes of 5% or more, and every switch to or from full speed. |
| Send notifications | Unraid alerts for problems that last more than a minute, for crashes and watchdog restarts, and a notice when a problem is resolved. |
| Drivers to load | Fan controller drivers loaded at boot and when the settings are saved, before fan control starts. Several separated by `;`, module options allowed, for example `it87 force_id=0x8628; nct6775`. Leave empty when another plugin loads the driver. |
| Identify: slow speed | Speed used for the slow step of *Identify*. Default 20%, which slows fans clearly without stopping them. 0% stops most fans. |
| Identify: seconds per step | How long the slow and the full speed step of *Identify* last. 3-30, default 5. |
| Disk types to monitor | Which disk types the array disks source looks at: parity, data, pools, unassigned devices, flash. Single disks can be unticked in the disk list, for example an NVMe cache that runs hot but isn't cooled by the case fans. |
| Full speed during parity check | Whether the array disks source asks for its max speed while a parity check or rebuild runs. |

Changes to groups and settings only take effect after *Apply*. Names are saved right away.

## Status page

*Settings > Utilities > PWM Fan Control* shows:

- Whether the service is running and, per group, the current speed, which source is driving it and a countdown
  while the fans wait to slow down.
- Every PWM output: name, current RPM, current setting and mode (and *DC mode* when the header isn't in PWM mode),
  paired RPM sensor, group and the result of its last speed test.
- Every temperature source with its current temperature and the groups using it.
- Per group: the fan curves with the current temperature of each source, and a chart of the last 24 hours of
  source temperatures and fan speed. History is kept in memory and starts over after a reboot.
- The disks the array disks source looks at, with their temperature and state.
- Warnings, such as unreadable sources, missing, stopped or moved fans.
- A hardware check: the loaded fan driver, the fan outputs found, ACPI resource conflicts in the kernel log and
  whether `acpi_enforce_resources=lax` is set and needed, with what to do.

The dashboard gets a *PWM Fan Control* tile with the speed of every group, the RPM of every named fan and the number
of warnings.

## Troubleshooting

- **No fans listed:** the sensor driver isn't loaded, or can't use the chip. The *Hardware check* at the bottom of the
  page says which: enter the driver under *Drivers to load* (or let *Dynamix System Temperature* detect it), and add
  `acpi_enforce_resources=lax` to the boot line if the kernel log shows an ACPI resource conflict.
- **"Fan ... runs much faster/slower than in its speed test":** fans were probably moved to other headers. Check
  which fan each header drives with *Identify*, fix the names and groups, then run the speed test again. If it's
  the same fan, it may be worn or blocked.
- **"Fan ... was not found":** the warning says whether the chip is missing (driver) or only that output (hardware
  or BIOS changed). In the second case, add the fan to its group again.
- **Identify: no RPM sensor reacted:** nothing is connected to that header, the fan has no RPM wire, or the header
  is in DC mode in the BIOS. If you saw or heard a fan change speed, it still works: pick its RPM sensor by hand or
  choose *None*.
- **A fan keeps jumping between speeds, or "its speed keeps being changed by something else":** another program
  controls the same fan. See *Conflict with other fan control*.
- **"Fan ... is not spinning (0 RPM)":** check the fan and its cable. If the fan was set below the speed it needs to
  start, raise the group's min speed (run a speed test to find it). If the wrong RPM sensor is paired, run *Identify*
  again or pick the sensor by hand.
- **GPU fans always at full speed:** the GPU can't be read. Check the warning on the page and run `nvidia-smi` in
  the terminal.
- **`nvidia-smi` is slow:** without persistence mode the driver starts up for every query. Enabling it
  (`nvidia-smi -pm 1`, for example in the `go` file) makes every check faster.
- **Array fans react slowly:** Unraid only refreshes disk temperatures every 30 minutes by default. Lower
  *Tunable (poll_attributes)* under *Settings > Disk Settings*, for example to 300 seconds.
- **"Service is not running":** the watchdog restarts it within a minute. If it keeps stopping, check the syslog.

## Uninstall

Remove the plugin in *Plugins*. This stops the service, hands every fan back to its BIOS mode and removes the
watchdog cron entry and the plugin files. Your settings and fan names stay on the flash drive in
`/boot/config/plugins/pwm.fan.control/`. Delete that folder to remove them too.

## How it is built

All plugin files live in `source/`:

| Path | Contents |
|---|---|
| `source/emhttp/` | Installed to `/usr/local/emhttp/plugins/pwm.fan.control/`: settings page, dashboard tile, API, service and scripts. |
| `source/emhttp/include/` | `config.php` (settings), `hardware.php` (hwmon, fan names, drivers, hardware check), `sources.php` (temperatures, curves), `service.php` (service, fan tests), `controller.php` (the control loop), `api.php` (web UI requests). `common.php` loads them all. |
| `source/emhttp/scripts/` | `daemon.php` (the service), `rc.pwmfancontrol` (start/stop/watchdog), `fantest.php` (Identify and speed test), `release.php` (hands every fan back to the BIOS). |
| `source/install.sh` | Runs after every install, update and boot: imports old settings and starts the service if enabled. |
| `source/remove.sh` | Runs when the plugin is removed. |
| `CHANGES.md` | Release notes shown in *Plugins*. |

`build.sh` embeds every file in a single `pwm.fan.control.plg`, so that one file is all Unraid needs:

```
./build.sh               # version = today's date
./build.sh 2026.11.16a   # explicit version
```

After changing anything under `source/`, add an entry to `CHANGES.md`, rebuild, and commit the `.plg` together with
the source. Set `PLUGIN_URL` when building to change the URL Unraid checks for updates.

### Manual check on a server

Install the `.plg` with fan control disabled and open the settings page. Every fan output, RPM sensor and
temperature source should be listed with live values. Run *Identify* on each output and a speed test on one, then
create a group, enable fan control and watch the speed follow the curve. Unplugging a paired fan for half a minute
should show the *not spinning* warning. Disabling fan control should put every fan back to its BIOS mode.

This project is not affiliated with Unraid or Nvidia.
