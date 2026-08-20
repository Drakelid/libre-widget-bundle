# LibreNMS Dashboard Widget Bundle — Implementation Specification

**Audience:** an AI developer implementing this from scratch.
**Deliverable:** ONE installable LibreNMS plugin package providing **SIX** dashboard widgets.
(A seventh custom widget is being **retired**, not ported — see §11.)

**Source material (all in this repo):**

| Path | What it is |
|---|---|
| `librenms-export2/files/` | The **existing working code**, extracted from the production LibreNMS install. This is the authority on behaviour. |
| `librenms-export2/users-widgets.json` | 19 live `users_widgets` rows for the six ported widgets — the authority on **persisted setting keys and values**. |
| `librenms-export2/librenms-about.txt` | Target environment. |
| `librenms-export2/dashboard-routes.txt` | `route:list` from the live system. |
| `librenms-export2/group-world-map-users-widgets.json` | The 2 rows migrated in §11. |
| `librenms-export2/git-status.txt` | Shows exactly which core files were modified. |
| `devicegroupdown/`, `topbandwith/`, `uplinkutilization/`, `upstemp/` | Screenshots — authority on **visual layout** for four of the six ported widgets. |

**Read `librenms-export2/files/` before writing any code.** This document tells you how to
restructure that code into a plugin, which bugs to fix on the way, and what must not change.

---

## 0. Why this project exists

Seven custom widgets currently exist as **untracked files dropped into the LibreNMS tree**
(six are ported by this project; the seventh is retired — §11), with their routes
hand-added to core's `routes/web.php`. `git-status.txt` shows them as `??` (untracked).

On **2026-08-19 the instance was upgraded to 26.8.1, and the upgrade overwrote `routes/web.php`** —
wiping every custom route registration. Compare:

- `librenms-export2/files/routes/web.php.bak.2026-07-09-132700` lines 426–431 — six of the seven registered (the map widget never was — §11)
- `librenms-export2/files/routes/web.php` (current) — **none of them**, and `dashboard-routes.txt`
  confirms they are absent from the live route table

So the widgets are, right now, **installed but invisible**: the controllers and blades are still on
disk, and `users_widgets` still holds 21 rows pointing at them (19 for the six ported widgets, 2 for the retired one), but nothing routes to them.

That is the whole justification for this work. A plugin survives upgrades; patched core files do not.

Encouraging precedent: `dashboard-routes.txt` already shows a **plugin-provided widget working on
this exact instance** —

```
POST  ajax/dash/weather-risk-map   widget.weather-risk-map › LibreNMS\Plugins › WeatherRiskMapWidgetController
```

The approach in this spec is therefore already proven on the target system.

---

## 1. Non-negotiable ground rules

1. **Do not patch LibreNMS core.** Everything ships inside the plugin package.
2. **Do not use PHP reflection** to mutate core's widget list. Not needed (§2.1).
3. All six ported widgets ship in **one** package: one composer package, one service provider, one toggle.
4. **Preserve the existing widget slugs exactly** (§3.2). 19 live dashboard placements depend on them.
5. Preserve the existing **setting keys** exactly (§3.3). Users' saved settings must survive.

---

## 2. Verified facts about how LibreNMS widgets work

Verified against LibreNMS **26.8.1** — the production version per `librenms-about.txt`.
`listWidgets()` and `formatResponse()` were diffed across 26.7.0 and master and are unchanged, so
these facts are stable across the version range.

**Target environment** (`librenms-about.txt`): LibreNMS 26.8.1 · Laravel 12.64.0 · PHP 8.4.23 ·
MariaDB 11.8.6 · Debian 13 · Redis cache/session/queue · **Routes CACHED · Views CACHED**.

> Because routes and views are cached in production, installation **must** run
> `php artisan route:clear` (and ideally `view:clear`). Document this prominently — a cached route
> table is the single most likely reason a correctly-built plugin appears to do nothing.

### 2.1 Widget discovery is route-driven, not database-driven

The `widgets` table was dropped in 2022. The "Add Widget" picker is built at runtime by
`App\Http\Controllers\DashboardController::listWidgets()`:

```php
public static function listWidgets(): Collection
{
    return collect(Route::getRoutes())->filter(function (\Illuminate\Routing\Route $route) {
        if (str_ends_with($route->uri, 'placeholder')) {
            return false;
        }
        return $route->getPrefix() === 'ajax/dash';   // <-- exact string match
    })->mapWithKeys(function (\Illuminate\Routing\Route $route) {
        $widget = Str::afterLast($route->uri, '/');   // <-- widget key
        $title = $widget;
        $controller = $route->getController();
        if (method_exists($controller, 'getTitle')) {
            $title = $controller->getTitle();
        }
        return [$widget => $title];
    })->sort();
}
```

Consequences:

- A widget appears **because a route exists whose prefix is exactly `ajax/dash`**.
- The **widget key** is the last URI segment, persisted into `users_widgets.widget`.
- `listWidgets()` **instantiates every widget controller** on each dashboard load, and calls
  `getTitle()`. Keep constructors trivial. Note all six ported `getTitle()` implementations call
  `getSettings()`, which runs `UserWidget::find(null)` in this context — harmless, returns defaults,
  but do not add work there.

### 2.2 Request / response contract

Core posts to `ajax/dash/{key}` with `id` (the `user_widget_id`), `dimensions` (`{x, y}` pixel size
of the widget body), and `settings` (1 = render settings form). It expects JSON
`{status, title, html, show_settings, settings}`.

### 2.3 The base class you extend

`App\Http\Controllers\Widgets\WidgetController` (abstract):

| Member | Behaviour |
|---|---|
| `protected string $name` | drives route / view / translation paths |
| `protected $defaults = []` | defaults, merged **under** stored settings |
| `getTitle(): string` | `__("widgets.$this->name.title")` |
| `getView(Request): View\|string` | `view("widgets.$this->name", $this->getSettings())` |
| `getSettingsView(Request): View` | `widgets.settings.$this->name` if it exists, else `widgets.settings.base` |
| `getSettings(bool $settingsView = false): array` | loads `UserWidget::find($id)`, **authorizes** `view` on its dashboard (403 otherwise), merges `$defaults`, injects `refresh` default 60 and `id` |
| `__invoke(Request)` | orchestrates, returns the JSON envelope |

`getSettings()` authorizes the *dashboard*. You must still scope **data** queries to the user (§4.5).

**Reserved keys:** if settings contain scalar `device_group` or `port_group`, the base class resolves
them to models in the settings view and appends their names to the widget title. The
`flapping-devices` widget uses scalar `device_group` and therefore gets this behaviour —
intentionally. The other five use `device_groups` (plural array) and do not. (Core's own
`worldmap` widget also uses the scalar key; relevant to the §11 migration.)

### 2.4 ⚠️ The `show_settings` trap — read this twice

```php
if ($view instanceof View) {
    $html = $view->__toString();
    $show_settings = (int) Str::startsWith($view->getName(), 'widgets.settings.');
} else {
    $html = (string) $view;
    $show_settings = (int) $this->show_settings;
}
```

The settings-vs-widget flag is derived from the **view name string**. A namespaced plugin view
(`nmsdashwidgets::widgets.settings.foo`) does not start with `widgets.settings.`, so `show_settings`
returns `0`. Core's JS then writes `data-settings="0"` back onto the element, and the next pencil
click toggles `0 -> 1` and re-renders the form again — **the user is stuck in the settings form and
can never get back to the widget.**

This does not affect the current hardcoded widgets (their views live in core's own directory), so it
is a **new** failure mode introduced by the port. It will not be caught by "does it look right".

**Required fix:** register the plugin's view directory as an *additional root location*, so
`widgets.settings.<slug>` resolves to a plugin file while keeping the un-namespaced name:

```php
View::getFinder()->addLocation(__DIR__ . '/../../resources/views');
```

Core paths are searched first, so this cannot shadow core views. With this in place the base class
defaults work unchanged and `show_settings` is correct. Keep a second namespaced registration
(`loadViewsFrom(..., 'nmsdashwidgets')`) for non-widget views.

**Acceptance test:** for each of the six widgets — pencil → form appears → pencil → **widget content
returns**. If the form renders twice, this bug is present.

### 2.5 Refresh and reload semantics

An element with `data-reload="false"` opts out of full HTML replacement and receives a jQuery
`refresh` event instead; a `destroy` event fires before teardown. Core's `widgets/settings/base.blade.php`
uses this on its `<form>`. Keep `@extends('widgets.settings.base')` in every settings blade — it
supplies the `refresh` field and the save button.

### 2.6 Core helpers and Blade components

The existing widgets already use core Blade components. **Keep using them** — they handle
permissions, overlib popups and lazy loading:

- `<x-device-link :device="$device"/>` — `app/View/Components/DeviceLink.php`
- `<x-port-link :port="$port">…</x-port-link>` — `app/View/Components/PortLink.php`
- `<x-graph :port="$port" type="port_bits" width="150" height="…"/>` — `app/View/Components/Graph.php`

`LibreNMS\Util\Url` also offers `lazyGraphTag()`, `graphTag()`, `deviceLink()`, `portLink()`,
`sensorLink()`, `portLinkDisplayClass()`, `sensorLinkDisplayClass()`.

### 2.7 Reference plugin

`dot-mike/nmswidgetalertrules` on GitHub is a published LibreNMS widget plugin. Mirror its structure
(`composer.json`, `src/Providers/WidgetServiceProvider.php`, `routes/web.php`); **improve on it** by
applying §2.4.

---

## 3. Package layout and naming

### 3.1 Package identity

| Item | Value |
|---|---|
| Composer package | `drakelid/librenms-dashboard-widgets` |
| Plugin slug | `nmsdashwidgets` |
| PSR-4 namespace | `Drakelid\NmsDashWidgets\` → `src/` |
| Service provider | `Drakelid\NmsDashWidgets\Providers\WidgetServiceProvider` |
| Requires | `php: ^8.3`, `librenms/plugin-interfaces: ^1.0` |
| LibreNMS floor | `>= 25.7`; developed and tested against 26.8.1 |

### 3.2 ⚠️ Widget slugs MUST NOT CHANGE

`users-widgets.json` contains 19 live placements keyed on these slugs. Renaming any of them
silently breaks every dashboard that uses it. Keep them **byte-identical**:

| Widget | Slug (`users_widgets.widget`, route segment, `$name`) | Live rows | Screenshots |
|---|---|---|---|
| Device Group Down Count | `device-group-down-count` | 3 | `devicegroupdown/` |
| Top Bandwidth Usage by Device Group | `top-bandwidth-device-group` | 4 | `topbandwith/` |
| Uplink Utilization Overview | `uplink-utilization-overview` | 6 | `uplinkutilization/` |
| UPS / Device Temperatures | `top-device-temperatures` | 3 | `upstemp/` |
| Flapping Devices / Unstable Links | `flapping-devices` | 2 | *(none — see §9)* |
| Recently Added Devices | `recently-added-devices` | 1 | *(none — see §10)* |

A seventh slug, `group-world-map` (2 rows), is **retired** rather than ported — those rows are
migrated to core's `worldmap` widget. See §11.

Note the fourth widget's slug is `top-device-temperatures` even though its live title is
"UPS Temperature Monitor" — the title is a per-placement override stored in `users_widgets.title`.
**Do not rename the slug to match the title.**

### 3.3 Setting keys MUST NOT CHANGE

Derived from the 19 live rows. Any key you rename silently resets that setting for existing users.
Full per-widget tables are in §5–§10. Cross-cutting notes:

- Values are stored as **strings**, including booleans (`"1"` / `"0"`) and numbers (`"60"`).
  Device group arrays are arrays of **strings**: `["6","2","1"]`. Normalise on read; never assume types.
- `title: null` is normal and means "use the default title".
- Five widgets use `device_groups` (array). `flapping-devices` uses `device_group`
  (scalar, reserved key — see §2.3). `top-device-temperatures` accepts **both**
  and merges them (§8.3).

### 3.4 Directory tree

```
librenms-dashboard-widgets/
├── composer.json
├── README.md · CHANGELOG.md · LICENSE
├── routes/web.php
├── resources/
│   ├── lang/en/widgets.php
│   ├── css/widgets.css
│   └── views/
│       ├── widgets/
│       │   ├── device-group-down-count.blade.php
│       │   ├── top-bandwidth-device-group.blade.php
│       │   ├── uplink-utilization-overview.blade.php
│       │   ├── top-device-temperatures.blade.php
│       │   ├── flapping-devices.blade.php
│       │   ├── recently-added-devices.blade.php
│       │   ├── partials/{stat-tile,status-badge,meter-bar,empty-state,regex-warning}.blade.php
│       │   └── settings/   (same six filenames)
│       └── plugin/main.blade.php
└── src/
    ├── Providers/WidgetServiceProvider.php
    ├── Hooks/MenuEntry.php
    ├── Support/{BundleWidgetController,Format,SafeRegex,DeviceGroups,Temperature}.php
    └── Http/Controllers/
        ├── PluginAdminController.php
        ├── Select/DeviceGroupsController.php
        └── Widgets/  (six controllers)
```

### 3.5 `routes/web.php`

Prefix must resolve to exactly `ajax/dash`. Nested `prefix()` calls concatenate:

```php
Route::group(['middleware' => ['web', 'auth']], function (): void {
    Route::namespace('Drakelid\NmsDashWidgets\Http\Controllers')->group(function (): void {
        Route::name('plugin.nmsdashwidgets.')->group(function (): void {

            Route::prefix('plugin/settings/nmsdashwidgets')->group(function (): void {
                Route::get('/', 'PluginAdminController@index')->name('index');
            });

            // Dashboard widgets — prefix MUST be exactly "ajax/dash"
            Route::prefix('ajax')->group(function (): void {
                Route::prefix('dash')->namespace('Widgets')->group(function (): void {
                    Route::post('device-group-down-count',    'DeviceGroupDownCountController');
                    Route::post('top-bandwidth-device-group', 'TopBandwidthDeviceGroupController');
                    Route::post('uplink-utilization-overview','UplinkUtilizationOverviewController');
                    Route::post('top-device-temperatures',    'TopDeviceTemperaturesController');
                    Route::post('flapping-devices',           'FlappingDevicesController');
                    Route::post('recently-added-devices',     'RecentlyAddedDevicesController');
                });
            });

            Route::prefix('ajax/select')->namespace('Select')->group(function (): void {
                Route::get('nmsdashwidgets-device-groups', 'DeviceGroupsController')
                    ->name('ajax.select.device-groups');
            });
        });
    });
});
```

Verify with `php artisan route:list --path=ajax/dash` — six new rows, prefix `ajax/dash`.

### 3.6 Service provider

```php
public function boot(PluginManagerInterface $pluginManager): void
{
    $pluginName = 'nmsdashwidgets';
    $pluginManager->publishHook($pluginName, MenuEntryHook::class, MenuEntry::class);

    if (! $pluginManager->pluginEnabled($pluginName)) {
        return;
    }

    $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
    $this->loadViewsFrom(__DIR__ . '/../../resources/views', $pluginName);
    View::getFinder()->addLocation(__DIR__ . '/../../resources/views');   // §2.4
    $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', $pluginName);
}
```

### 3.7 Translations

`resources/lang/en/widgets.php`:

```php
return [
    'device-group-down-count'     => ['title' => 'Device Group Down Count'],
    'top-bandwidth-device-group'  => ['title' => 'Top Bandwidth Usage by Device Group'],
    'uplink-utilization-overview' => ['title' => 'Uplink Utilization Overview'],
    'top-device-temperatures'     => ['title' => 'Top Device Temperatures'],
    'flapping-devices'            => ['title' => 'Flapping Devices / Unstable Links'],
    'recently-added-devices'      => ['title' => 'Recently Added Devices'],
];
```

These live in the plugin namespace, so each controller overrides `getTitle()`:

```php
public function getTitle(): string
{
    return $this->getSettings()['title'] ?: __('nmsdashwidgets::widgets.' . $this->name . '.title');
}
```

That `?:` behaviour matches all six ported controllers — keep it.

---

## 4. Shared conventions

The six data-driven controllers currently duplicate near-identical private helpers with subtle inconsistencies.
Consolidating them is the main engineering win of this port.

### 4.1 `Support/BundleWidgetController`

Extends core's `WidgetController`. Provides `getTitle()` (§3.7), a shared `normalizeSettings()`,
`layoutFor(Request)` (reads posted `dimensions` for `auto` display mode), and `emptyState()`.

### 4.2 `Support/DeviceGroups` — replaces four private helpers

`DeviceGroupDownCountController::normalizeGroupIds()`, `TopBandwidth…::cleanIds()`,
`UplinkUtilization…::cleanIds()` and `TopDeviceTemperatures…::resolveDeviceGroupIds()` are four
implementations of one idea. Replace with one class providing:

- `ids(mixed $value): int[]` — accepts array, comma string, or JSON string (the temperature widget
  handles all three; the others do not — adopt the most tolerant behaviour). Filters to positive ints,
  dedupes, preserves order.
- `scopeToDevices(Builder $q, array $ids): Builder` — the `whereIn(device_id, subquery)` pattern the
  existing widgets use against `device_group_device`.
- `namesFor(array $ids): string` and `membershipMap(array $ids, array $deviceIds): Collection`.
- `selectable(User $user): Collection` — **access-filtered** group list (see §4.5).

> **Do not use core's `scopeInDeviceGroup()` for multi-group filtering.** It takes a single id:
> `->where('device_group_id', $deviceGroup)`. The existing widgets correctly avoid it with a
> `whereIn` subquery; `flapping-devices` uses it correctly with its scalar `device_group`.
> For large id lists prefer `whereIntegerInRaw` to dodge the 1000-item bind limit.

### 4.3 `Support/SafeRegex` — replaces three inconsistent implementations

The widgets currently disagree on how to compile user regex:

| Widget | Behaviour |
|---|---|
| `uplink-utilization-overview` | wraps as `/…/i`, escaping `/`; invalid → falls back to default |
| `top-device-temperatures` | tries the pattern **raw first** (`@preg_match($regex, '')`), then wraps as `~…~i` |

The raw-first path lets a user supply their own delimiters and modifiers. There is no RCE risk (the
`e` modifier was removed in PHP 7), but it is inconsistent and surprising. **Standardise on
always-wrap**: `'/' . str_replace('/', '\/', $pattern) . '/i'`.

Requirements:
1. Store the **bare pattern** as typed (e.g. `uplink|upstream|trunk|wan|core|backbone|transport`).
2. Validate with `@preg_match($compiled, '')`; check `preg_last_error()`.
3. On failure, **drop that filter** and surface a non-blocking inline warning. Both regex widgets
   already track this (`regex_was_invalid`, `regexErrors`) — keep and unify it.
4. Abort a filter that hits `PREG_BACKTRACK_LIMIT_ERROR`, log once, warn inline.
5. Cap pattern length at 512 chars.
6. Apply regex **in PHP after** the database has narrowed the set. Never push user regex into SQL.

### 4.4 `Support/Format` — replaces two identical `formatBits()` copies

`TopBandwidthDeviceGroupController` and `UplinkUtilizationOverviewController` contain byte-identical
`formatBits()`. Extract once. Behaviour to preserve exactly:

```php
$units = ['bps','Kbps','Mbps','Gbps','Tbps','Pbps'];
// divide by 1000 while >= 1000
return number_format($bits, $index === 0 ? 0 : 2) . ' ' . $units[$index];
```

So: SI 1000-based, **0 decimals for raw bps, 2 decimals above** (`6.10 Gbps`, `142.61 Mbps`).
Percentages: `number_format($v, 1) . '%'`; null → `'n/a'`.
Temperatures: `rtrim(rtrim(number_format($v,1,'.',''),'0'),'.') . ' °C'` → `37 °C`, `36.5 °C`.

Port rate columns are **octets/sec** — multiply by 8 for bits.

### 4.5 Authorization — mandatory, and currently incomplete

Existing widgets correctly use `Port::hasAccess()`, `Device::hasAccess()`, `Sensor::hasAccess()`,
`DeviceGroup::hasAccess()` for their **main** queries. But three places bypass access control when
resolving device-group *names* for display:

- `TopBandwidthDeviceGroupController::deviceGroupNames()` → `DeviceGroup::whereIn('id', $ids)`
- `UplinkUtilizationOverviewController::deviceGroupNames()` and its `getSettingsView()`
- `TopDeviceTemperaturesController::resolveDeviceGroupIds()` and `device_groups_available`
  → `DeviceGroup::query()->orderBy('name')->get()` — **lists every group to every user**

`DeviceGroupDownCountController` does it correctly with `DeviceGroup::hasAccess(Auth::user())`.

**Fix in the port:** route every device-group lookup through `DeviceGroups::selectable($user)` so
group names and the settings picker are access-filtered. A user must not learn group names they have
no rights to, nor hand-edit a group id into their settings JSON to read another tenant's data.

### 4.6 Presentation

Each existing widget blade carries its own inline `<style>` block with hardcoded
`rgba(255,255,255,0.06)`-style colours — dark-theme-only, and re-emitted once per widget instance
(three `device-group-down-count` placements = three copies of a 900-line-blade's CSS).

**In the port:** move all widget CSS into one `resources/css/widgets.css`, namespace every class
(`nmsdw-…`), and emit it once. Use theme-aware colours (LibreNMS 26.x ships Tailwind utilities with
a `tw:` prefix and `tw:dark:` variants) rather than hardcoded rgba, so light mode is not broken.
Keep semantic colours: green = OK, amber = warning, red = critical/down.

Every widget must degrade gracefully from ~250px to ~1200px wide, and must not introduce a second
scroll container inside core's already-scrolling `#widget_body_*`.

### 4.7 Performance

The reference instance matches **1156 uplinks** and has device groups with **632 devices**.

The most serious existing problem: `UplinkUtilizationOverviewController` calls `->get()` on **all
accessible up ports** with no limit, then regex-filters in PHP. On this dataset that materialises
tens of thousands of Port models per widget refresh, every 60 seconds, per placement.
`TopDeviceTemperaturesController` does the same with `->limit(100000)`.

**In the port:** narrow in SQL as far as possible, then stream with `chunkById()` and keep only
running aggregates plus a bounded top-N heap. Never hold the full matched set in memory.

Also: eager-load relations (no N+1), select only needed columns, and keep summary statistics
computed over the **full matched set** while displaying only top-N (§7.3).

Target: widget JSON response under 500 ms on the reference dataset. Measure and report.

---

## 5. Widget — Device Group Down Count

**Slug:** `device-group-down-count` · **Source:** `DeviceGroupDownCountController.php` (177 lines),
`device-group-down-count.blade.php` (952 lines) · **Screenshots:** `devicegroupdown/`

### 5.1 Settings (authoritative)

| Key | Default | Control | Notes |
|---|---|---|---|
| `title` | `null` | text | |
| `device_groups` | `[]` | select2 multi (chips) | **empty ⇒ widget shows nothing** (§5.2) |
| `display_mode` | `'auto'` | select | `auto`, `cards`, `compact`, `list`, `summary` — **five** values |
| `density` | `'comfortable'` | select | `comfortable`, `compact` |
| `card_min_width` | `170` | number | clamped **120–320**, fallback 170 |
| `show_header` | `'1'` | Yes/No select | |
| `show_total` | `'1'` | Yes/No select | |
| `show_group_totals` | `'1'` | Yes/No select | |
| `exclude_ignored_disabled` | `'1'` | Yes/No select | `1` = skip ignored/disabled; `0` = count every device with `status = 0` |
| `background_color` | `'#d9534f'` | colour | validated `/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/` |
| `text_color` | `'#ffffff'` | colour | same validation |
| `refresh` | `60` | number | from base |

> `background_color` and `text_color` are **not visible in the screenshot** (which is cropped below
> "Show group totals") but are live in the DB — one row has `"background_color":"#80ff80"`. They are
> real settings. Keep them.

### 5.2 Data

Preserve exactly:

- **Empty `device_groups` ⇒ empty widget.** The controller returns `collect()` when no groups are
  selected; it does *not* fall back to "all groups". Keep this, and render a helpful empty state
  telling the user to pick a group.
- Counts via `withCount(['devices as total_count', 'devices as down_count'])`, each closure applying
  `hasAccess()` and, when `exclude_ignored_disabled`, `disabled = 0 AND ignore = 0`.
  `down_count` adds `devices.status = 0`.
- Groups sorted by the **user's selection order**:
  `sortBy(fn ($g) => array_search((int) $g->id, $groupIds, true))`.
- `total_down` is `$groups->sum('down_count')` — a **sum of per-group counts**, so a device in two
  selected groups counts twice. Preserve this for parity; note it in the README. (Changing it to a
  distinct count is a behaviour change and needs the maintainer's sign-off — §15.)

### 5.3 Layout

Per screenshot: full-width tinted banner with a red circular `!`, very large count, caption
`DEVICES DOWN`; then one row per group with group name, `OK`/`DOWN` pill, large count, and a
right-aligned `N total` / `N healthy` block, row tinted green when `down = 0` and red otherwise.

`cards` uses `grid-template-columns: repeat(auto-fill, minmax({card_min_width}px, 1fr))`.
`summary` shows only the banner. `auto` selects from posted `dimensions.x`
(`< 320px` → summary, `320–520` → list, `> 520` → cards). `compact` density reduces padding ~25%.

---

## 6. Widget — Top Bandwidth Usage by Device Group

**Slug:** `top-bandwidth-device-group` · **Source:** `TopBandwidthDeviceGroupController.php` (164 lines),
blade (231 lines) · **Screenshots:** `topbandwith/`

### 6.1 Settings

| Key | Default | Clamp | Control |
|---|---|---|---|
| `title` | `null` | | text |
| `top_count` | `10` | 1–50 | number |
| `time_interval` | `15` | 1–1440 | number ("Last polled within minutes") |
| `device_groups` | `[]` | | select2 multi — empty ⇒ **all** accessible devices |
| `interface_filter` | `null` | | select2 single (`ports.ifType`) |
| `show_graphs` | `1` | | checkbox |
| `show_utilisation` | `1` | | checkbox |
| `refresh` | `60` | | number |

Note: unlike widget 5, **empty `device_groups` means all devices** here.

### 6.2 Data

Preserve the existing query: `Port::hasAccess()->isValid()->isUp()` with eager-loaded device
(`device_id, hostname, sysName, status, os, display`), `poll_time > now - time_interval`,
`COALESCE(...,0)` on both rate columns, ordered by
`(COALESCE(ifInOctets_rate,0) + COALESCE(ifOutOctets_rate,0)) DESC`, limited to `top_count`.
Group filter via `whereIn('ports.device_id', subquery on device_group_device)`.
When no groups selected: `->has('device')`.

Derived: `total = (in + out) * 8`; **utilisation is total-based**,
`min(100, (total_bps / ifSpeed) * 100)`, `null` when `ifSpeed <= 0` → label `n/a`.
(Contrast §7.2, which uses peak.)

`device_group_names` per row comes from a single membership join, not per-row queries — keep that.

### 6.3 Layout

Header `Top {N} bandwidth ports` with muted subtitle naming groups and window. Columns:
Device · Interface (`ifName` bold, `ifAlias` muted beneath) · Usage (formatted total + proportional
bar) · In / Out · Util. (hidden if `show_utilisation` = 0) · Graph (`<x-graph type="port_bits">`,
hidden if `show_graphs` = 0) · Group. Sticky header; below ~600px drop Graph and Group, then fold
In/Out into Usage.

---

## 7. Widget — Uplink Utilization Overview

**Slug:** `uplink-utilization-overview` · **Source:** `UplinkUtilizationOverviewController.php`
(627 lines), blade (656 lines) · **Screenshots:** `uplinkutilization/`

### 7.1 Settings

| Key | Default | Clamp | Notes |
|---|---|---|---|
| `title` | `null` | | |
| `device_groups` | `[]` | | empty ⇒ all accessible |
| `uplink_regex` | `uplink\|upstream\|trunk\|wan\|core\|backbone\|transport` | | invalid ⇒ silently reverts to this default |
| `exclude_regex` | `''` | | invalid ⇒ ignored. Live example: `Kundeport\|te\|hu` |
| `top_count` | `20` | 1–100 | |
| `time_interval` | `15` | 1–1440 | |
| `warning_threshold` | `70` | 1–100 | if `>= critical`, forced to `critical - 10` |
| `critical_threshold` | `90` | 1–100 | |
| `show_graphs` | `1` | | |
| `show_device_group` | `1` | | live rows store `"0"` |

### 7.2 Data

Matched against `ifName + ifDescr + ifAlias` joined by spaces, case-insensitive.

**Utilisation is peak-based** — `min(100, (max(in_bps, out_bps) / ifSpeed) * 100)`. Confirmed by both
the code and the screenshot (TX 142.61 Mbps on 1.00 Gbps → 14.3%, labelled `peak 142.61 Mbps`).

Status: `null` → `unknown` (`label-default`); `>= critical` → `critical` (`label-danger`);
`>= warning` → `warning` (`label-warning`); else `ok` (`label-success`).
Sort by utilisation desc, tie-break on `total_bps` desc; `unknown` sorts last via `?? -1`.

### 7.3 Summary tiles span the full matched set

The six tiles (`uplink_count`, `max_utilisation_label`, `avg_utilisation_label`, `total_in_label`,
`total_out_label`, and critical/warning/ok/unknown counts) are computed over **all** matched uplinks;
`take($topCount)` is applied only to the display collection. The screenshot confirms:
`MATCHED UPLINKS 1156` with ~20 rows rendered. Preserve this exactly — and see §4.7 for doing it
without loading every port into memory.

### 7.4 Layout

Header with `uplink regex: <code>…</code> · last {N} minutes`. Six stat tiles (wrapping, never
horizontally scrolling). Table: DEVICE · UPLINK INTERFACE · UTILIZATION (status pill, `peak …`,
meter bar, `Warning {w}% · Critical {c}%`) · TRAFFIC (RX/TX/Total) · SPEED · GRAPH · GROUP
(only when `show_device_group`). Surface `regex_was_invalid` / `exclude_regex_was_invalid` as an
inline warning — currently tracked but easy to lose in the port.

---

## 8. Widget — Top Device Temperatures (a.k.a. UPS Temperature Monitor)

**Slug:** `top-device-temperatures` · **Source:** `TopDeviceTemperaturesController.php` (527 lines),
blade (210 lines) · **Screenshots:** `upstemp/`

This is the most intricate of the six. Read the source carefully before restructuring.

### 8.1 Settings

| Key | Default | Clamp | Notes |
|---|---|---|---|
| `title` | `null` | | live rows override with `"UPS Temperature Monitor"` |
| `device_count` | `10` | 1–100 | **devices**, not sensors — see §8.2 |
| `time_interval` | `60` | 0–10080 | `0` disables the filter (matches the screenshot hint) |
| `device_groups` | `[]` | | multi |
| `device_group` | `null` | | **legacy scalar**, still merged — §8.3 |
| `only_up` | `true` | | `status = 1 AND disabled = 0 AND ignore = 0` |
| `include_module_sensors` | `false` | | `false` = chassis only |
| `warn_temp` | `70` | | if `>= limit_temp`, forced to `limit_temp - 1` |
| `limit_temp` | `90` | | |
| `sensor_include_regex` | `''` | | live value: `outlet\|inlet\|` |
| `sensor_exclude_regex` | `''` | | |

Booleans parse via `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` — keep, since
stored values are strings.

The screenshot's "Device filter" and "Temperature source" dropdowns map onto the booleans `only_up`
and `include_module_sensors` respectively. Keep the dropdown presentation and the boolean storage.

### 8.2 One row per device

`->groupBy(device_id)->map(fn ($g) => $g->sortByDesc('display_current')->first())->sortByDesc('display_current')->take($device_count)`

So: **the hottest sensor per device**, then the hottest N devices. Hence `device_count`, not
`sensor_count`. This is easy to get wrong when reimplementing.

### 8.3 Legacy setting migration

`resolveDeviceGroupIds()` merges the modern `device_groups` (array / JSON string / CSV string) with
the legacy scalar `device_group`, dedupes, and validates the ids exist. It then writes
`device_group` back to the first selected id for runtime compatibility. **Preserve this** — some
stored rows may still carry the legacy key.

### 8.4 Module-sensor exclusion — improve this

`looksLikeInterfaceModuleTemperature()` is a ~50-line **text heuristic**: hard-excludes on
`transceiver|optic|optical|sfp|qsfp|xfp|gbic|dom|laser|port`, plus interface-name patterns
(`gi|te|fa|eth|…\d+/\d+`, Juniper `ge-0/0/0`), plus `module` + slot-number rules.

LibreNMS has a **structural** signal that is more reliable. Verified at 26.8.1: transceiver sensors
are tagged `sensors.group = 'transceiver'`. Core proves it in
`app/View/Components/TransceiverSensors.php`:

```php
Sensor::where('device_id', $this->transceiver->device_id)
    ->whereNotNull('entPhysicalIndex')
    ->where('entPhysicalIndex', $this->transceiver->entity_physical_index)
    ->where('group', 'transceiver')
```

**In the port:** filter on `group != 'transceiver'` **in SQL** when `include_module_sensors` is
false, and keep the text heuristic as a secondary pass for devices that do not populate `group`.
This both speeds up the query and reduces false positives (the current heuristic excludes anything
containing the word "port", which can catch legitimate chassis sensors).

Before finalising, run this against production and pin the rule to what it returns:

```sql
SELECT `group`, entPhysicalIndex_measured, COUNT(*) AS n
FROM sensors
WHERE sensor_class = 'temperature' AND sensor_deleted = 0
GROUP BY `group`, entPhysicalIndex_measured
ORDER BY n DESC;
```

Document the final rule in the README.

### 8.5 Temperature scaling — preserve exactly

`temperatureScaleFactor()` handles vendors reporting deci-Celsius:

- `hasRawDeciCelsiusValues()` — if any of `sensor_current`/`sensor_limit`/`sensor_limit_warn` is
  `>= 200.0` and `value/10` lands in `(-80, 130]`, use factor `0.1`.
- Otherwise use `sensor_multiplier / sensor_divisor`, but only if
  `scaleWouldCreateSaneTemperature()` agrees (rejects factors that shrink already-small values or
  produce `> 180`).
- Else factor `1.0`.

This is load-bearing correctness logic. Move it to `Support/Temperature` **unchanged**, and cover it
with unit tests (§13.2) before touching anything else in this widget.

### 8.6 Other preserved details

- Query filters `sensor_deleted = 0`, `sensor_class = 'temperature'`, `sensor_current IS NOT NULL`.
- Device filter is applied via `whereHas('device', …)` including `last_polled` when `time_interval > 0`.
- Regex target text is lowercased `hostname + displayName + sensor_descr + sensor_type + sensor_index`.
- `barPercent()` scales against `limit_temp` when positive, else the max shown value; clamped 3–100.
- Status uses the **widget's** `warn_temp`/`limit_temp`, not the sensor's own limits.
- The view receives `excludedModuleCount` and `excludedRegexCount` — keep surfacing them as a muted
  footer so users understand why sensors disappeared.

### 8.7 Layout

Per screenshot: one card per device — device name (linked) with sensor description beneath, large
`37 °C`, meter bar with truncating caption `Limit: 90 °C · Warn…`, and an `OK`/`WARN`/`CRIT` pill.
Row tinted by status. Caption truncates with ellipsis rather than wrapping.

---

## 9. Widget — Flapping Devices / Unstable Links

**Slug:** `flapping-devices` · **Source:** `FlappingDevicesController.php` (197 lines),
blade (205 lines) · **No screenshot** — derive layout from the blade.

### 9.1 Settings

| Key | Default | Clamp | Control |
|---|---|---|---|
| `title` | `null` | | text |
| `lookback_hours` | `24` | 1–168 | number |
| `min_changes` | `3` | 2–100 | number |
| `limit` | `15` | 1–100 | number ("Maximum rows") |
| `show_type` | `'all'` | `all`\|`devices`\|`ports` | select |
| `device_group` | `null` | | **scalar**, reserved key (§2.3) |
| `refresh` | `60` | 30–3600 | number |

### 9.2 Data

Two `DB::table('eventlog')` aggregations over `datetime >= now - lookback_hours`, restricted to
device ids the user can access:

- **Device flaps:** `type = 'device'` OR message matching `Device status|status changed|changed status`.
- **Port flaps:** `type = 'port'` OR message matching `ifOperStatus|oper.*status|link.*up|link.*down|changed.*up|changed.*down`, left-joined to `ports` on `eventlog.reference = ports.port_id`.

Both additionally require the message to match `up|down`, and select
`COUNT(*) as changes`, `MIN/MAX(datetime)`, and the newest message via
`SUBSTRING_INDEX(GROUP_CONCAT(message ORDER BY datetime DESC SEPARATOR ' || '), ' || ', 1)`.

Merged, filtered to `changes >= min_changes`, sorted by `[changes, last_change]` desc, `take(limit)`.
Then per row: `state` from `stateFromMessage()` (regex `(?:to|now|status[:\s]+)\s+(down|up)`, else the
last standalone `up`/`down`, else `Unknown`), `severity` (`danger` at `>= 3x min_changes`, `warning`
at `>= 2x`, else `info`), and `short_message` truncated to 95 chars.

Summary card row: total changes, device count, port count, last change.

### 9.3 Known weaknesses — fix during the port

This widget is the least robust of the six.

1. **`GROUP_CONCAT` truncation.** MariaDB's default `group_concat_max_len` is 1024 bytes; with many
   events the "last message" can be silently cut. Replace the
   `GROUP_CONCAT`/`SUBSTRING_INDEX` trick with a correlated subquery or a window function
   (MariaDB 11.8 supports `ROW_NUMBER()`), which is both correct and faster.
2. **`REGEXP` on `eventlog.message`** cannot use an index and scans the table. Prefer
   `eventlog.type` plus `reference`, falling back to message matching only where necessary.
   Constrain by `datetime` first.
3. **`whereIn('e.device_id', $deviceIds)`** can exceed the 1000-bind limit on large installs — use
   `whereIntegerInRaw`, or join against the group table instead of materialising ids.
4. **MariaDB-specific SQL** (`REGEXP`, `GROUP_CONCAT`, `SUBSTRING_INDEX`). Acceptable given LibreNMS
   requires MySQL/MariaDB — note it in the README rather than abstracting it away.
5. The message regexes are English-only and format-dependent. Document this limitation.

### 9.4 Settings form — two real bugs to fix

`settings/flapping-devices.blade.php` has defects the port must correct:

1. **Duplicate `refresh` field.** The blade `@extends('widgets.settings.base')`, which already renders
   a `refresh` input, *and* declares its own. Two inputs share `name="refresh"` and `id="refresh"`.
   **Delete the widget's own copy** and let the base template supply it.
2. **Non-unique DOM ids.** Fields use `id="title"`, `id="limit"` etc. without the `-{{ $id }}` suffix
   every other widget uses. With two placements on one dashboard the ids collide and labels focus the
   wrong input. **Suffix every id with `-{{ $id }}`.**
3. **The device group picker is unfinished** — a hidden input plus a free-text box whose help text
   admits "set the group after adding the widget and saving once". Replace with the same select2
   picker the other widgets use (single-select, since the key is scalar).

---

## 10. Widget — Recently Added Devices

**Slug:** `recently-added-devices` · **Source:** `RecentlyAddedDevicesController.php` (39 lines),
blade (32 lines) · **No screenshot.** The simplest of the six — port it verbatim.

### 10.1 Settings

| Key | Default | Control |
|---|---|---|
| `title` | `null` | text (labelled "Custom Title") |
| `device_count` | `10` | number, `min=1 max=50` in the form; controller applies `max(1, …)` |
| `refresh` | `60` | number |

### 10.2 Data and layout

`Device::hasAccess($user)->orderByDesc('inserted')->orderByDesc('device_id')->limit($device_count)`.

Table: Device (status pill — `default` when disabled, else `success`/`danger` by status — plus
`<x-device-link>`) · Hardware · OS · Added (`inserted->diffForHumans()`, with the raw timestamp as the
`title` attribute; `Unknown` when null). Empty state: "No devices found".

Clamp `device_count` to 1–50 in the controller to match the form, which it currently does not.

---

## 11. Retired widget — Group World Map (migrate to core's `worldmap`)

**Slug:** `group-world-map` · **Source:** `librenms-export2/files/app/Http/Controllers/Widgets/GroupWorldMapController.php`
· **Decision: RETIRE. Do not port it.** (Maintainer decision, 2026-08-20.)

This widget is **not** part of the bundle. Do not create a controller, a route, a blade, a
translation key, or a settings form for it. The work in this section is one data migration plus
documentation.

### 11.1 Why it is being retired

`GroupWorldMapController` subclasses core's `App\Http\Controllers\Widgets\WorldMapController` and
overrides only default values. Verified against LibreNMS 26.8.1:

- Core's `WorldMapController` `$defaults` **already contain `'device_group' => null`**.
- Core's `resources/views/widgets/settings/world-map.blade.php` (line 49) **already renders a Device
  group picker**: `<select name="device_group" … data-placeholder="All Devices">`.
- Its two overrides are redundant: `getView()` only calls `parent::getView()`, and
  `getSettingsView()` returns `widgets.settings.world-map` — exactly what the base class resolves.

So device-group filtering on the world map is stock LibreNMS. The custom class contributes different
default values and nothing else — and both live placements store **all nine settings keys
explicitly**, so none of those defaults are in effect anyway.

It was also the only class in the bundle reaching into core internals, which is the exact fragility
this project exists to remove.

### 11.2 The settings map 1:1 — no transformation needed

Core's `world-map` accepts precisely the keys the two live rows already store:

| Key | In live rows | Accepted by core `world-map` |
|---|---|---|
| `title` | ✅ `"Device Group Map"` | ✅ |
| `init_lat` | ✅ | ✅ |
| `init_lng` | ✅ | ✅ |
| `init_zoom` | ✅ `"6"`, `"8"` | ✅ (form allows 0–18, step 0.1) |
| `init_layer` | ✅ `"Streets"` | ✅ |
| `group_radius` | ✅ `"1"` | ✅ |
| `status` | ✅ `"0,1"` | ✅ |
| `device_group` | ✅ `"1"`, `"3"` | ✅ (scalar, reserved key — §2.3) |
| `refresh` | ✅ `"60"` | ✅ (from `widgets.settings.base`) |

**The `settings` JSON blob is migrated untouched.** Only `users_widgets.widget` changes.

Because `title` is stored per-placement, both widgets keep displaying **"Device Group Map"** after
migration. Visually, nothing changes for the user.

### 11.3 ⚠️ The target slug is `worldmap`, not `world-map`

Core's world map widget is inconsistent about its own name, and getting this wrong silently breaks
both rows:

| Thing | Value |
|---|---|
| Controller `$name` | `world-map` |
| Blade views | `widgets.world-map`, `widgets.settings.world-map` |
| **Route URI segment** | **`worldmap`** |
| Route name | `widget.worldmap` |

`users_widgets.widget` must hold the **route segment**, because `listWidgets()` derives the key via
`Str::afterLast($route->uri, '/')` and the dashboard JS posts to `ajax_url + '/dash/' + data_type`
(§2.1, §2.2). Confirmed in `librenms-export2/dashboard-routes.txt`:

```
POST  ajax/dash/worldmap   widget.worldmap › Widgets\WorldMapController
```

**Migrate to `worldmap`.** A row set to `world-map` would POST to a non-existent route and render an
error panel.

### 11.4 The migration — recommended approach

Only two rows on one instance, so prefer an explicit, auditable manual step over a plugin migration
that silently rewrites core dashboard data on install.

**Step 1 — back up the affected rows:**

```sql
SELECT * FROM users_widgets WHERE widget = 'group-world-map';
```

Expect exactly two rows: `user_widget_id` 39 (dashboard 18) and 68 (dashboard 20), both `user_id` 4.
Save the output before continuing.

**Step 2 — verify the target route exists** (guards against running this on an instance where core's
widget was renamed):

```bash
php artisan route:list --path=ajax/dash | grep worldmap
```

Must show `ajax/dash/worldmap`. If it does not, **stop** and re-check the LibreNMS version.

**Step 3 — migrate:**

```sql
UPDATE users_widgets
SET widget = 'worldmap'
WHERE widget = 'group-world-map';
```

**Step 4 — verify:**

```sql
SELECT user_widget_id, widget, title, settings
FROM users_widgets
WHERE user_widget_id IN (39, 68);
```

Both rows must read `worldmap`, with `title` and `settings` unchanged. Then load dashboards 18 and 20
and confirm each map renders, is centred as before, and is still filtered to its device group
(1 and 3 respectively).

The statement is idempotent — re-running it affects zero rows.

### 11.5 Optional — ship it as a plugin migration instead

If the maintainer prefers this to run automatically on install, the plugin may ship it under
`database/migrations/` (the service provider already calls `loadMigrationsFrom`). If so:

```php
public function up(): void
{
    DB::table('users_widgets')
        ->where('widget', 'group-world-map')
        ->update(['widget' => 'worldmap']);
}

public function down(): void
{
    // Intentionally a no-op. Reversing would recreate rows pointing at a widget
    // this bundle no longer provides, i.e. broken dashboard panels.
}
```

Two caveats to state in the README if you take this route:

1. It mutates **core's** `users_widgets` table from a plugin — a side effect on install that users do
   not expect. Announce it in the CHANGELOG.
2. The irreversible `down()` means a plugin rollback leaves the data migrated. That is deliberate and
   correct here, but it must be documented.

### 11.6 Cleanup on the production host

Once migration is verified, delete the now-dead file so a future export does not resurrect it:

```
app/Http/Controllers/Widgets/GroupWorldMapController.php
```

It is untracked (`??` in `group-world-map-git-status.txt`), so removing it touches nothing in git.
There is no blade or settings blade to remove — it never had any.

### 11.7 README wording

Document the retirement so the behaviour is not mistaken for a regression:

> **Group World Map (retired).** Earlier versions of this dashboard used a custom `group-world-map`
> widget. LibreNMS's built-in **World Map** widget already supports filtering by device group, so the
> custom widget was removed and existing placements were migrated to it. Saved settings — centre,
> zoom, layer, grouping radius, status filter and device group — were preserved, and the widget
> title is unchanged.

### 11.8 Historical note

`group-world-map` was **never registered** in either `routes/web.php.bak.2026-07-09-*` backup, and
`group-world-map-routes.txt` is empty. Unlike the other six — which lost their routes in the 26.8.1
upgrade — this one appears to have been unroutable for longer, so its two placements were probably
already rendering an error panel. Migration therefore *restores* function rather than removing it.

---

## 12. What must not regress

A checklist of behaviour that is easy to lose while restructuring:

1. Widget slugs and setting keys unchanged (§3.2, §3.3).
2. `getTitle()` returns the custom title when set, falling back to the translation.
3. Empty `device_groups` means **nothing** for `device-group-down-count` but **everything** for the
   other group-filtered widgets.
4. Utilisation is **total**-based for `top-bandwidth-device-group` and **peak**-based for
   `uplink-utilization-overview`.
5. Uplink summary tiles span the full matched set, not the displayed top-N.
6. Temperature widget shows one row per device (hottest sensor), and its deci-Celsius scaling logic
   is preserved verbatim.
7. Group ordering follows the user's selection order in `device-group-down-count`.
8. Invalid regex degrades to a warning, never a 500 or a silently empty widget.
9. `flapping-devices` keeps the **scalar** `device_group` key — do not "modernise" it to the
   plural `device_groups`, or its live placements lose their filter.
10. The two migrated map rows (§11) point at `worldmap`, **not** `world-map`. Anyone "fixing" that
    to match core's `$name` breaks both dashboards.

---

## 13. Testing and acceptance

### 13.1 Manual acceptance

1. `php artisan route:list --path=ajax/dash` shows all six new routes with prefix `ajax/dash`,
   plus core's unchanged `worldmap` route.
2. All six appear in the widget picker with correct titles. `group-world-map` does **not** appear.
3. **Settings toggle round-trip for each widget** (§2.4): pencil → form → pencil → widget content.
4. **Existing placements still work.** Restore `users-widgets.json` into a test database, enable
   the plugin, and confirm all 19 rows render with their saved settings intact — including the row
   with `background_color: "#80ff80"` and the one with 23 selected device groups.
4a. **Map migration verified** (§11.4). Restore `group-world-map-users-widgets.json`, run the
   `UPDATE`, and confirm both rows render as core World Map widgets — still titled
   "Device Group Map", still centred on their saved coordinates, still filtered to device groups
   1 and 3. Re-run the `UPDATE` and confirm it affects zero rows (idempotent).
5. Settings persist across page reload and widget refresh.
6. Disabling the plugin removes the widgets without 500s on dashboards still referencing them.
7. A restricted non-admin sees only permitted devices/ports/sensors — **and only permitted device
   group names** in both the widget body and the settings picker (§4.5). Verify with a real account.
8. Invalid regex in the uplink and temperature widgets produces an inline warning.
9. Each widget renders correctly at ~250px, ~500px and ~1200px.
10. No PHP notices/warnings in `storage/logs/laravel.log` during a full exercise.
11. Widget responses under 500 ms on the production dataset; report measured numbers, especially for
    the uplink widget (§4.7).

### 13.2 Automated tests

- `Support/Temperature` — deci-Celsius detection, multiplier/divisor path, sanity rejections.
  **Write these before refactoring §8.5.**
- `Support/SafeRegex` — valid, invalid, empty, over-length, catastrophic backtracking.
- `Support/Format` — `formatBits` boundaries (999 bps, 1000 bps, `6.10 Gbps`), percent, temperature.
- `Support/DeviceGroups::ids()` — array, CSV string, JSON string, mixed junk, string ids `["6","2"]`.
- Utilisation maths: total-based vs peak-based, `ifSpeed = 0` → `n/a`.
- Uplink summary computed over matched set, not display set.
- Settings normalisation for every widget against the **real** JSON blobs in `users-widgets.json`.

### 13.3 Non-goals

No new tables; no core code changes; no polling/discovery modules; no new alerting.

One **data** migration is in scope: the §11 `users_widgets` update retiring `group-world-map`.
It adds no schema and is the only write this project makes to core-owned data.

---

## 14. Build order

1. Package skeleton, service provider, routes, translations. Verify §13.1 items 1–2 with one stub.
2. **Prove §2.4 (the `show_settings` fix) on the stub before porting any real widget.**
3. `Support/` layer with unit tests — `Temperature` first (§8.5), then `Format`, `SafeRegex`,
   `DeviceGroups`.
4. `recently-added-devices` — trivial, validates the whole plumbing end to end.
5. `top-bandwidth-device-group` — establishes the port query and table view patterns.
6. `uplink-utilization-overview` — reuses that pipeline; add streaming aggregation (§4.7).
7. `device-group-down-count` — device aggregation, five display modes.
8. `top-device-temperatures` — run the §8.4 SQL first, then port using the tested `Temperature` helper.
9. `flapping-devices` — including the §9.3 query rework and §9.4 form fixes.
10. `group-world-map` retirement (§11) — run the migration, verify both dashboards, then delete the
    dead controller from the host. **Do not build a widget for it.**
11. Shared CSS consolidation (§4.6), plugin admin page, README, CHANGELOG.
12. Full acceptance pass (§13.1), including the 19-row restore test and the §11 migration check.

---

## 15. Open questions for the maintainer

Proceed with the stated assumption; flag in the README.

1. ~~**`group-world-map` — port or retire?**~~ **Resolved 2026-08-20: retire and migrate.** See §11.
   Remaining sub-question: should the migration run automatically as a plugin migration (§11.5) or
   as the documented manual `UPDATE` (§11.4)? *Assumption: manual — two rows, one instance, and a
   plugin silently rewriting core dashboard data on install is a surprising side effect.*
2. **Grand-total semantics** in `device-group-down-count`: currently a sum of per-group counts, so a
   device in two selected groups is counted twice. Change to a distinct count?
   *Assumption: preserve existing behaviour.*
3. **Empty `device_groups` in `device-group-down-count`** renders an empty widget rather than all
   groups. Intentional? *Assumption: yes, preserve; add an explanatory empty state.*
4. **`flapping-devices` and `recently-added-devices` have no screenshots.** Layout will be derived
   from their blades. Confirm the current rendering is what you want, or supply screenshots.
5. **Vendor/package name.** *Assumption: `drakelid/librenms-dashboard-widgets`, slug `nmsdashwidgets`.*
6. **Colour settings** (`background_color`, `text_color`) are stored but their effect is not visible
   in the screenshot. Confirm they still drive anything in the 952-line blade, or drop them.
