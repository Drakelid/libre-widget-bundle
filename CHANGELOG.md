# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-20

Initial release. Converts six previously hardcoded LibreNMS dashboard widgets into an
upgrade-safe plugin.

### Added

- Six dashboard widgets: `device-group-down-count`, `top-bandwidth-device-group`,
  `uplink-utilization-overview`, `top-device-temperatures`, `flapping-devices`,
  `recently-added-devices`.
- Shared `Support/` layer (`Format`, `SafeRegex`, `Temperature`, `DeviceGroups`, `Cast`)
  replacing helpers that had been duplicated, with subtle differences, across widgets.
- Unit test suite for the Support layer, runnable without a LibreNMS install.
- Plugin admin page listing the widgets and their keys.
- Access-filtered select2 endpoint for the device group pickers.

### Changed

- Transceiver and optic temperature sensors are excluded structurally via
  `sensors.group = 'transceiver'`, with the previous text heuristic kept as a fallback.
- Uplink and temperature widgets stream rows in chunks rather than materialising the
  full result set.
- Regex handling is unified: patterns are always wrapped as `/.../i`, capped at 512
  characters, and abandoned with an inline warning on catastrophic backtracking.
- Widget CSS is consolidated into one stylesheet injected once per page.

### Fixed

- Device group names and the settings picker are now access filtered. Previously every
  group name was listed to every user.
- Flapping Devices settings form rendered a duplicate `refresh` input, and used bare DOM
  ids that collided when two copies shared a dashboard.
- `GROUP_CONCAT` separator changed from `" || "` to a sequence that cannot occur in an
  eventlog message.
- `recently-added-devices` now clamps `device_count` to the 1-50 range its form advertises.

### Removed

- The `group-world-map` widget. LibreNMS's built-in World Map already supports device
  group filtering; see `docs/RETIRE-GROUP-WORLD-MAP.md` to migrate existing placements.
