# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.7.5] - 2026-08-21

### Fixed

- **BGP Session Health mislabelled healthy peers as "shut".** Status was decided by
  checking the admin field before the session state, so an established peer on a device
  that does not report `bgpPeerAdminStatus` was classed `unknown` and rendered with a
  "shut" label. Core defaults that field to `'unknown'` for Juniper and `null` for
  Huawei, so this affected whole vendor families.

  An established session is now treated as up regardless of the admin field. The fault
  case still matches core's `BgpPeer::scopeInAlarm()`: admin up and not established.
- The state column now shows the protocol state the device actually reported. "admin
  shut" is only claimed when the admin status is explicitly `stop`, `halted`, `down` or
  `disabled` -- never inferred from a missing value.
- Summary tile relabelled "Shut / unknown", since it counts sessions that are not
  established and not known to be admin-up.

### Notes

- Prefix counts are summed across address families by design. They are also summed
  across VRFs where the same peer address appears twice on a device: `bgpPeers` keys the
  VRF by `vrf_id` while `bgpPeers_cbgp` uses `context_name`, so there is no clean join.
  Documented in the code.

## [1.7.4] - 2026-08-21

### Security

- The Settings hook now checks `plugin.admin` itself before rendering the widget
  enable/disable form, rather than relying solely on core's gating.

  Enabling or disabling widgets was already restricted -- LibreNMS applies
  `can:plugin.admin` middleware to both the settings route and the save route, and
  `PluginSettingsController` calls `authorize('plugin.admin')` in both `__invoke()` and
  `update()`. The check added here is defence in depth, so a future change to either of
  those cannot silently expose the form.

  The check uses the `Auth` facade. Injecting `App\Models\User` would not work:
  LibreNMS does not bind that class, so the container supplies an empty instance whose
  permission checks always fail -- the cause of the "missing view" bug fixed in 1.7.2.

### Notes

- `plugin.admin` is a spatie permission. `Gate::before` in core's AppServiceProvider
  grants every ability to the `admin` role, so administrators pass automatically;
  anyone else needs the permission granted explicitly.
- The stored value is never trusted: `WidgetCatalog::enabled()` re-filters it to known
  slugs on every read, so a tampered setting cannot register an arbitrary route.

## [1.7.3] - 2026-08-21

### Fixed

- **Optical Light Levels: transmit readings could be labelled receive.** Direction was
  detected from the sensor description with a pattern that matched a bare "in", and
  receive was tested first -- so a sensor described as "Tx power in dBm" or "Laser
  output power in dBm" came back as RX, and was then hidden entirely by the
  transmit-only filter. Bare "in" and "out" no longer match; the explicit
  input/output/recv/xmit wordings still do.
- **An empty widget did not say why.** "Only show optics that report a low threshold"
  is on by default, and plenty of optics report power without limits -- so every
  reading could be discarded while the widget said only "No optical readings matched".
  The count of discarded readings was calculated but rendered only when there were rows
  to show it alongside. The empty state now reports how many readings were found and
  which filter consumed them, and names the setting to change.

### Notes

- Verified against LibreNMS: divisor and multiplier are applied at poll time
  (`includes/polling/functions.inc.php`), so `sensor_current` is already final dBm.
  The widget correctly applies no further scaling, unlike the temperature widget which
  needs it for vendors reporting deci-Celsius.

## [1.7.2] - 2026-08-21

### Fixed

- **The plugin settings page rendered "missing view".** The Settings hook was being
  registered correctly, but silently filtered out before it ran.

  `PluginManager::hooksFor()` invokes `authorize()` through the service container.
  LibreNMS does not bind `App\\Models\\User`, so type-hinting it does not yield the
  logged-in user -- the container satisfies the parameter by constructing a brand new,
  empty `User`, on which every `can()` check fails. The hook was dropped, `call()`
  returned an empty array, and `PluginSettingsController` fell back to its
  `plugins.missing` view. Nothing appeared in the log, because nothing threw.

  Core's own hook base classes carry the same signature and get away with it only
  because their default `authorize()` returns true without consulting the user.

  Both hooks now declare `authorize()` with no parameters. Authorisation is unchanged:
  `PluginSettingsController` calls `authorize('plugin.admin')` before the hook runs, and
  the route carries `can:plugin.admin` middleware.

## [1.7.1] - 2026-08-21

### Fixed

- **Accent colours had almost nothing to paint.** The setting was applied correctly --
  the `nmsdw-accent-*` class reached the DOM and the custom property resolved -- but only
  five rules consumed it, and four of those live in the cards, compact and tiles layouts
  where status colours legitimately override them. In the default table layout the accent
  coloured a single 3px stripe beside the heading, and nothing at all when the heading was
  switched off.

  The accent now also colours the table header row, stat tile borders and values, heading
  text, inline code chips, neutral progress bars and the empty state. Sixteen rules
  instead of five.

### Notes

- Status colours are still untouched: ok, warning and critical read identically under
  every accent.
- Text recolouring is scoped to `:not(.nmsdw-accent-default)`, so an installation that
  never changes the setting looks exactly as it did before. Choosing an accent is what
  makes it visible.
- Tinted backgrounds use `color-mix()`, with an `@supports` fallback to the plain surface
  colour on older browsers; borders and text still carry the accent there.

## [1.7.0] - 2026-08-21

### Added

- **Per-widget enable/disable.** Overview -> Plugins -> Plugins Admin -> Settings now
  lists every widget in the bundle with a tick box. Unticking one removes it from the
  dashboard "Add Widget" list without uninstalling the plugin.
- `Support/WidgetCatalog.php` is now the single source of truth for which widgets exist
  and which are switched on; routes are generated from it.
- Saving the settings rebuilds the route cache automatically. LibreNMS builds the widget
  list by scanning routes and caches that table in production, so a change would
  otherwise not appear until someone ran `route:clear` by hand. The settings page still
  shows the command in case the rebuild fails.

### Changed

- The plugin's own admin page has been removed in favour of LibreNMS's native plugin
  settings page, implemented through `SettingsHook`. Its route
  (`plugin/settings/nmsdashwidgets`) **collided with core's**
  `plugin/settings/{plugin:plugin_name}`; whichever registered first won. The menu entry
  now links to core's page.

### Notes

- With the setting absent, every widget is enabled -- so upgrading changes nothing, and
  a future release adding a widget does not leave it silently switched off.
- Disabling a widget that is still on a dashboard leaves an error panel in its place.
  Nothing is deleted; re-enabling restores it. The settings page warns about this.

## [1.6.2] - 2026-08-21

### Fixed

- **Site Power and Battery displayed nonsense.** Cards showed runtimes like
  "-8715378 min" and every one was painted critical.

  Two separate problems:

  1. **Implausible readings were displayed verbatim.** A lot of UPS hardware reports
     battery runtime as an unsigned SNMP counter that arrives as a large negative
     number. Because that is below any sane threshold, it also drove every card to
     critical. Runtime, charge, voltage and power readings are now range checked
     (runtime 0 to 30 days, charge 0-100, voltage above 0) and discarded when outside
     it, rather than shown or used to decide severity. Discarded readings are counted
     and reported in a footer note.
  2. **The scope was far too wide.** Any device reporting a voltage counted as a "site",
     which on this network meant 1827 of them rather than the UPS fleet. A new
     "Only devices with battery data" setting, on by default, requires a charge or
     runtime sensor before a device appears. Turn it off to include anything reporting
     voltage or power.

- The header now reports how many sites have battery data, rather than how many devices
  had any power-related sensor at all.

## [1.6.1] - 2026-08-21

### Fixed

- **Flapping Devices counted the same events twice.** A device appeared as two entries
  with identical change counts -- once correctly as a device, once as a nameless "port"
  -- and the summary tiles were inflated to match.

  Three faults combined:

  1. LibreNMS logs port changes with eventlog type **`interface`**, not `port`
     (`includes/polling/ports.inc.php`), so matching on `'port'` found nothing and the
     loose message regex was doing all the work.
  2. That fallback included `changed.*up|changed.*down`, which matches a device status
     message such as "Device status changed to Up" -- so device events were pulled into
     the port query as well.
  3. `CONCAT("Port ref ", e.reference)` returns NULL when `reference` is NULL, which is
     why the phantom row had no name.

  The two queries are now mutually exclusive: device rows require type `device` or a
  null `reference`; port rows require a non-null `reference` and a type other than
  `device`. No eventlog row can satisfy both.

- Port matching now recognises the real `interface` type, so genuine port flaps are
  found by type rather than by guessing at message wording.
- `up|down` matching is word-anchored, so an interface described as "Backup" or "Uplink"
  no longer counts as an up/down event.
- Port rows always carry a label, falling back to "Unknown port".

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
