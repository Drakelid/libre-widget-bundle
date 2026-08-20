# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-08-20

### Fixed

- Plugin installation failed with a PHP fatal error: `MenuEntry` extended
  `LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook`, which is an interface, not an
  abstract class. It now implements it and provides the `authorize()` / `handle()`
  methods the plugin manager actually calls. The LibreNMS plugin documentation shows an
  `extends` form with a `data()` method that does not match the shipped
  `librenms/plugin-interfaces` package.

  Because Laravel auto-discovers the package's service provider on every request,
  this fatal took the entire LibreNMS UI down with a 500 rather than just failing
  the install.

### Changed

- The service provider's `boot()` now catches every throwable and logs it instead of
  letting it escape. Laravel registers this provider on every request, so an
  unhandled error here is a site-wide outage rather than a missing widget. The plugin
  now degrades to "widgets absent" instead.
- `install.sh` verifies that LibreNMS still boots after installing and rolls back if it
  does not. Rollback now clears all four places a Laravel package registers itself:
  `composer.json`, `composer.lock`, `vendor/composer/installed.json` and
  `bootstrap/cache/packages.php`. Clearing only some of them was what turned a failed
  install into an unbootable application.

### Added

- `tests/load-check.php` -- loads every framework-independent class in a subprocess and
  asserts its contracts (implements/extends/required methods). Compile-time fatals such
  as `class X extends <interface>` are invisible to `php -l`; this is the check that
  would have caught the 1.0.0 outage.
- `tests/blade-lint.php` -- compiles every Blade template and lints the generated PHP.
- `.github/workflows/ci.yml` -- runs lint, Blade lint, the load check, PHPUnit and
  `composer validate` on PHP 8.2 and 8.4, for every push and every tag.
- `recover.sh` -- restores a LibreNMS server left unbootable by a failed plugin install.

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
