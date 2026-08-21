# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.0] - 2026-08-21

### Added

- **Editable heading inside each widget.** Every widget now has a `heading` setting that
  replaces the heading rendered in the widget body. Leave it empty to keep the widget's
  own wording.

### Fixed

- Renaming a widget only changed the bar along the top. The heading inside the body was
  hardcoded, so a widget retitled "Core uplinks" still said "Uplink Utilization Overview"
  one line further down. The two are now independently settable, and the "Widget title"
  field says which one it controls.

### Notes

- LibreNMS's own `title` setting continues to drive the top bar; nothing about that
  changed. Widgets where the heading is dynamic -- Top Bandwidth shows "Top 10 bandwidth
  ports" -- keep generating it unless a heading is entered, in which case the override
  wins outright.

## [1.5.0] - 2026-08-21

### Added

- **Column visibility** on the eight table widgets. Each settings form lists its columns
  as checkboxes, so a dashboard can drop the device group column when everything is one
  group, the sparkline on a narrow widget, or the BGP last-error text on a wall display.
- `Support/Columns.php` holds the per-widget column definitions, coercion and the
  defaults.

### Changed

- The per-column toggles that shipped earlier -- `show_graphs`, `show_utilisation`,
  `show_device_group`, `show_transceiver_details`, `show_prefixes` -- are now expressed
  as columns. Their checkboxes have been removed from the settings forms to avoid two
  controls for one thing.
- Columns that are essential to a widget (the device, and the value it exists to report)
  are marked required: they render always and appear greyed out in the form. A row with
  no device and no measurement says nothing.

### Notes

- **Saved widgets are unaffected.** When `columns` has never been set, visibility is
  seeded from the old `show_*` values, so a widget with graphs turned off keeps them off.
  Those keys remain in the defaults purely so that migration keeps working.
- Column choices apply to the cards and compact layouts too, not just the table.

## [1.4.0] - 2026-08-21

### Added

- **Layout and styling settings on every widget.** All eleven now expose the same
  presentation controls, so a dashboard can be tuned as a whole instead of each widget
  having its own vocabulary:
  - **Layout** — `auto`, plus the layouts that suit the widget: `table` or `rows` (its
    original look), `cards`, `compact`, and `tiles` for the two sensor widgets. `auto`
    resolves against the widget body width the dashboard reports: the dense line view
    when narrow, cards when wide.
  - **Density** — comfortable or compact.
  - **Accent colour** — default, blue, green, amber, red, violet or slate. Tints neutral
    chrome only; status colours stay green, amber and red so an alert always reads the
    same.
  - **Striped rows** and **show the in-body heading**.
  - **Minimum card width** for the cards layout.
- `Support/Presentation.php` centralises the defaults, the per-widget layout lists,
  coercion and the `auto` resolution.
- `partials/nmsdw-records.blade.php` renders the cards, compact and tiles layouts once
  for every widget; each widget only reduces its rows to a neutral record shape.
- Widgets that had no in-body heading (recently added devices, flapping devices, device
  temperatures) gained one, so the new setting means something for them too.

### Notes

- Every default reproduces the layout the widget already had, so existing placements are
  unchanged until someone edits them.
- Device Group Down Count keeps its own richer layout set (seven layouts plus sort and
  hide-healthy) and gains the shared accent and striping options.

## [1.3.0] - 2026-08-21

### Fixed

- The plugin page showed the version as `vv1.1.2`. `Composer\InstalledVersions::getPrettyVersion()`
  returns the git tag verbatim, so a `v1.1.2` tag already carries its own prefix and the
  template was adding a second. Prefixing now happens in one place, and only for versions
  starting with a digit -- a branch install reads as `dev-main`, not `vdev-main`.

### Changed

- **Device Group Down Count restyled**, with each display mode now a distinct layout
  rather than variations on one row style:
  - **List** (default, unchanged in shape) gains a proportion bar under the group name.
  - **Cards** are real cards -- large count, health bar, healthy/total footer -- instead
    of list rows re-flowed by CSS.
  - **Compact** is now its own single-line layout with a status dot and inline bar,
    rather than a squeezed list. Existing widgets set to `compact` will look different.
  - **Summary** renders a proper hero with the share of the estate affected and the
    worst group named, instead of a banner plus a stray line of text.
  - **Tiles** (new) -- dense colour-coded squares, sized for wall displays with many
    groups.
  - **Bars** (new) -- ranked comparison where bar length is the proportion of the group
    that is down. A raw count hides this: 2 of 2 down is an outage, 22 of 500 is not.
- The banner is now a hero block carrying totals and, for a single group, that group's
  own figures. `background_color` and `text_color` drive it through CSS custom
  properties instead of inline styles on every child.

### Added

- `sort` setting: selection order (default, unchanged), most devices down, largest share
  down, or name.
- `hide_healthy` setting. Header and hero totals still cover every selected group, so
  hiding rows never changes the numbers.

### Notes

- Both new settings default to the previous behaviour, and the five original
  `display_mode` values keep working, so saved widgets render as before -- except
  `compact`, which is deliberately a new layout.

## [1.2.0] - 2026-08-21

Five widgets covering the ISP gaps identified in `docs/future-spec.md`: optical,
routing and power were absent from both this bundle and LibreNMS core.

### Added

- **Optical Light Levels** (`optical-light-levels`) — transceiver RX/TX levels ranked by
  margin above the low threshold, joined to the `transceivers` table for vendor, model
  and wavelength. LibreNMS polls `sensor_class = 'dbm'` and ships nothing that displays
  it; this bundle's temperature widget explicitly excludes transceiver sensors, so the
  data was being collected and discarded. Ranks on the LOW limits, unlike the
  temperature widget.
- **BGP Session Health** (`bgp-session-health`) — sessions administratively up but not
  established, recently re-established, or with a sharp drop in accepted prefixes.
  Prefix counts and deltas come from `bgpPeers_cbgp`, so a collapsing table is visible
  without touching RRD.
- **Site Power and Battery** (`site-power-status`) — battery runtime, charge and DC
  voltage aggregated per device or per location. Severity for `state` sensors uses
  LibreNMS's own `state_generic_value` rather than pattern-matching vendor text.
- **Customer Ports Down** (`customer-port-status`) — customer-facing ports that are
  administratively up but operationally down, matched on an `ifAlias` convention.
- **Poller Health** (`poller-health`) — devices whose data has gone stale and poller
  nodes that stopped reporting. A dashboard showing all-green because polling stopped is
  worse than a blank one.

### Notes

- All five stream with `chunkById`, scope every query with `hasAccess()`, and reuse the
  existing `Cast`, `Format`, `SafeRegex` and `DeviceGroups` helpers.
- Optical, BGP and power depend on what the installation collects. Empty output means
  the data is not being polled, not that the widget is broken — see the verification
  queries in `docs/future-spec.md`.
- Capacity planning (95th percentile) was deliberately not built: it needs RRD reads per
  port, which requires a cache table and a scheduled job. Still listed as future work.

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
