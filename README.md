# LibreNMS Dashboard Widget Bundle

Eleven dashboard widgets for LibreNMS, packaged as a plugin.

These widgets previously lived as hand-placed files inside the LibreNMS tree, with their
routes added directly to core's `routes/web.php`. The LibreNMS 26.8.1 upgrade overwrote
that file and silently removed every registration, leaving the widgets installed but
unreachable. Packaging them as a plugin means upgrades no longer break them.

## Widgets

| Widget | Key | What it shows |
|---|---|---|
| Device Group Down Count | `device-group-down-count` | Down device counts per selected device group, with a combined total |
| Top Bandwidth Usage by Device Group | `top-bandwidth-device-group` | Busiest ports by combined in + out throughput |
| Uplink Utilization Overview | `uplink-utilization-overview` | Regex-matched uplinks ranked by peak utilisation, with summary stats across all matches |
| Top Device Temperatures | `top-device-temperatures` | Hottest devices by temperature sensor, one row per device |
| Flapping Devices / Unstable Links | `flapping-devices` | Devices and ports that changed state repeatedly in a lookback window |
| Recently Added Devices | `recently-added-devices` | Most recently added devices, newest first |

### ISP additions (1.2.0)

Chosen from the gap analysis in [`docs/future-spec.md`](docs/future-spec.md).

| Widget | Key | What it shows |
|---|---|---|
| Optical Light Levels | `optical-light-levels` | Transceiver RX/TX levels ranked by margin above the low threshold |
| BGP Session Health | `bgp-session-health` | Sessions admin-up but not established, recently re-established, or losing prefixes |
| Site Power and Battery | `site-power-status` | Battery runtime, charge and DC voltage, per device or per site |
| Customer Ports Down | `customer-port-status` | Customer-facing ports admin-up but operationally down |
| Poller Health | `poller-health` | Devices whose data has gone stale, and pollers that stopped reporting |

Three of these depend on what your installation actually collects. Check before
expecting output:

```sql
SELECT COUNT(*) FROM sensors WHERE sensor_class='dbm' AND sensor_deleted=0;   -- optical
SELECT COUNT(*) FROM bgpPeers;                                                -- BGP
SELECT sensor_class, COUNT(*) FROM sensors WHERE sensor_deleted=0 GROUP BY 1; -- power
```

An empty result means the widget has nothing to display — it is not misconfigured.

The widget keys are permanent identifiers stored in `users_widgets.widget`. **Never
rename one** — existing dashboard placements reference them by key.

## Requirements

- LibreNMS **25.7 or newer** (developed and tested against **26.8.1**)
- PHP 8.2+
- MySQL / MariaDB (the Flapping Devices widget uses `REGEXP` and `GROUP_CONCAT`)

## Installation

### Scripted (recommended)

[`install.sh`](install.sh) installs or updates the plugin, clears and rebuilds the
caches, enables the plugin and verifies that all six widget routes registered. Re-run
the same command to update later.

```bash
curl -fsSLO https://raw.githubusercontent.com/Drakelid/libre-widget-bundle/main/install.sh
chmod +x install.sh
sudo ./install.sh
```

Useful flags: `--dir` (LibreNMS path, default `/opt/librenms`), `--user`, `--version`,
`--no-enable`, `--uninstall`, `--dry-run`. Run `./install.sh --help` for details.

### Manual

From the LibreNMS base directory, as the `librenms` user:

```bash
./lnms plugin:add drakelid/librenms-dashboard-widgets '^1.0'
php artisan route:clear
php artisan view:clear
./lnms plugin:enable nmsdashwidgets
php artisan route:cache
```

Then enable it under **Overview → Plugins → Plugins Admin** if the CLI could not, and
add widgets from a dashboard via **Add Widget**.

> **`php artisan route:clear` is mandatory.** LibreNMS caches routes in production, and
> widgets are discovered by scanning the route table. A stale route cache is by far the
> most common reason a correctly installed plugin appears to do nothing. If you also see
> stale markup, run `php artisan view:clear`.

### Docker

```dockerfile
ARG VERSION=librenms:26.8.1
FROM librenms/$VERSION

RUN echo $'#!/usr/bin/with-contenv sh\n\
set -e\n\
if [ "$SIDECAR_DISPATCHER" = "1" ] || [ "$SIDECAR_SYSLOGNG" = "1" ] || [ "$SIDECAR_SNMPTRAPD" = "1" ]; then\n\
  exit 0\n\
fi\n\
lnms plugin:add drakelid/librenms-dashboard-widgets\n\
php artisan route:clear\n\
' > /etc/cont-init.d/99-nmsdashwidgets.sh
```

## Upgrading from the hardcoded widgets

Saved settings are preserved. Both the widget keys and every settings key are unchanged,
so existing dashboard placements keep working with their stored configuration.

After enabling the plugin, delete the old files from the LibreNMS tree so they cannot
shadow or confuse a future upgrade:

```
app/Http/Controllers/Widgets/DeviceGroupDownCountController.php
app/Http/Controllers/Widgets/FlappingDevicesController.php
app/Http/Controllers/Widgets/GroupWorldMapController.php
app/Http/Controllers/Widgets/RecentlyAddedDevicesController.php
app/Http/Controllers/Widgets/TopBandwidthDeviceGroupController.php
app/Http/Controllers/Widgets/TopDeviceTemperaturesController.php
app/Http/Controllers/Widgets/UplinkUtilizationOverviewController.php
resources/views/widgets/device-group-down-count.blade.php
resources/views/widgets/flapping-devices.blade.php
resources/views/widgets/recently-added-devices.blade.php
resources/views/widgets/top-bandwidth-device-group.blade.php
resources/views/widgets/top-device-temperatures.blade.php
resources/views/widgets/uplink-utilization-overview.blade.php
resources/views/widgets/settings/*.blade.php   (the six matching files)
```

### Group World Map (retired)

Earlier dashboards used a custom `group-world-map` widget. LibreNMS's built-in **World
Map** widget already supports filtering by device group, so the custom widget was
removed and existing placements should be migrated to it. Saved settings — centre, zoom,
layer, grouping radius, status filter and device group — are preserved, and the widget
title is unchanged.

See [`docs/RETIRE-GROUP-WORLD-MAP.md`](docs/RETIRE-GROUP-WORLD-MAP.md) for the procedure.
The target slug is `worldmap`, **not** `world-map`.

## Behaviour worth knowing

Carried over deliberately from the original widgets:

- **Device Group Down Count shows nothing when no groups are selected.** It does not
  fall back to "all groups".
- **Its grand total is a sum of per-group counts**, so a device belonging to two selected
  groups is counted twice. Changing this to a distinct count would alter numbers people
  are used to, so it was left as is.
- **Two different utilisation formulas.** Top Bandwidth uses *total* traffic
  (`(in + out) / ifSpeed`); Uplink Utilization uses *peak* (`max(in, out) / ifSpeed`).
  This is intentional.
- **Uplink summary tiles describe every matched uplink**, not just the rows displayed.
- **Top Device Temperatures shows one row per device** — that device's hottest sensor.
- **Flapping Devices uses the scalar `device_group` key**, which is a reserved LibreNMS
  settings key. Core appends the group name to the widget title as a result. Do not
  change it to the plural `device_groups` used by the other widgets.
- **Optical Light Levels ranks by the LOW threshold**, unlike the temperature widget
  which uses the high side. For optics it is falling receive power that predicts failure.
- **`bgpPeerFsmEstablishedTime` is seconds since the session came up**, not a timestamp.
- **`ports.ifLastChange` is SNMP TimeTicks relative to device uptime**, not wall clock,
  and resets on reboot. Customer Ports Down shows a dash rather than a wrong duration
  when the value is unusable.

## Changes from the original implementation

- Transceiver and optic sensors are now excluded **structurally** via
  `sensors.group = 'transceiver'` rather than only by text heuristics. The heuristic is
  retained as a fallback for devices that do not populate that column.
- The Uplink and Temperature widgets **stream results in chunks** instead of loading
  every matching row into memory.
- Device group names and the settings picker are now **access filtered**; the original
  listed all group names to every user.
- Regex handling is consistent across widgets, with length limits and protection against
  catastrophic backtracking. Invalid patterns show an inline warning instead of silently
  emptying the widget.
- The Flapping Devices settings form no longer renders a duplicate `refresh` field, and
  its inputs use unique DOM ids so two copies can coexist on one dashboard.
- Widget CSS is shared and injected once per page rather than inlined per widget.

## If a LibreNMS install goes wrong

[`recover.sh`](recover.sh) restores a server left unbootable by a failed plugin
install (symptom: every page shows "Whoops, looks like something went wrong", and
`storage/logs/laravel.log` reports a missing or uncompilable class from this package).

```bash
sudo bash recover.sh
```

A Laravel package registers itself in **four** places -- `composer.json`,
`composer.lock`, `vendor/composer/installed.json` and `bootstrap/cache/packages.php`.
Removing it from some but not all leaves Laravel loading a provider class that no
longer exists, which fails every request. `recover.sh` clears all four, and falls back
to installing a no-op placeholder provider if anything is still wrong.

## Development

The `Support/` layer is free of Eloquent and framework facades so it can be tested
without a LibreNMS install:

```bash
composer install
composer test          # load check + Blade lint + unit tests
```

Three gates run in CI on every push and tag, on PHP 8.2 and 8.4:

| Check | Catches |
|---|---|
| `php -l` | syntax errors |
| `tests/blade-lint.php` | templates that compile to invalid PHP |
| `tests/load-check.php` | compile-time fatals and broken class contracts |
| `vendor/bin/phpunit` | logic regressions in the `Support/` layer |

`tests/load-check.php` exists because of a real outage: 1.0.0 declared a hook class as
`extends` on an interface. That is a compile-time fatal, so `php -l` passed it happily,
and because Laravel loads this package's service provider on every request it took the
whole LibreNMS UI down. Loading each class in a subprocess is the only way to catch
that before release.

**Never tag a release that has not passed CI.** A broken release of this package does
not break a widget; it breaks LibreNMS.

Widget controllers and views need a real LibreNMS instance; see [`TESTING.md`](TESTING.md)
for the manual acceptance checklist.

Further reading:

- [`docs/SPEC.md`](docs/SPEC.md) — how LibreNMS widget discovery works, why the view
  finder is registered the way it is, and the behaviour that had to be preserved from
  the original hardcoded widgets.
- [`docs/RETIRE-GROUP-WORLD-MAP.md`](docs/RETIRE-GROUP-WORLD-MAP.md) — migrating the
  retired map widget.
- [`docs/future-spec.md`](docs/future-spec.md) — gap analysis for an ISP: candidate
  widgets for optical light levels, BGP session health and site power, with data
  sources verified against the LibreNMS schema.

## Licence

MIT.
