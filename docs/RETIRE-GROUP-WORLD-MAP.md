# Retiring the `group-world-map` widget

The old custom `group-world-map` widget is **not** part of this bundle. It duplicated
functionality LibreNMS already ships, so existing placements are migrated to core's
built-in **World Map** widget instead.

## Why

`GroupWorldMapController` subclassed core's `App\Http\Controllers\Widgets\WorldMapController`
and overrode only default values. Verified against LibreNMS 26.8.1:

- Core's `WorldMapController` `$defaults` already contain `'device_group' => null`.
- Core's `resources/views/widgets/settings/world-map.blade.php` already renders a
  **Device group** picker.
- Its `getView()` only called `parent::getView()`, and its `getSettingsView()` returned
  `widgets.settings.world-map` — exactly what the base class resolves anyway.

Device-group filtering on the world map is therefore stock LibreNMS. The custom class
contributed different default values and nothing else. Both live placements store all
nine settings keys explicitly, so none of those defaults were ever in effect.

It was also the only class reaching into core internals — the exact fragility this
plugin exists to remove.

## The settings map 1:1

Core's `world-map` accepts precisely the keys the existing rows store, so the
`settings` JSON blob migrates **untouched**. Only `users_widgets.widget` changes.

| Key | Accepted by core `world-map` |
|---|---|
| `title` | yes |
| `init_lat`, `init_lng`, `init_zoom` | yes |
| `init_layer` | yes |
| `group_radius` | yes |
| `status` | yes |
| `device_group` | yes (scalar) |
| `refresh` | yes (from the base settings template) |

Because `title` is stored per placement, both widgets keep displaying their existing
title. Visually nothing changes.

## The target slug is `worldmap`, not `world-map`

Core's world map widget is inconsistent about its own name:

| Thing | Value |
|---|---|
| Controller `$name` | `world-map` |
| Blade views | `widgets.world-map`, `widgets.settings.world-map` |
| **Route URI segment** | **`worldmap`** |
| Route name | `widget.worldmap` |

`users_widgets.widget` must hold the **route segment**. `DashboardController::listWidgets()`
derives the key with `Str::afterLast($route->uri, '/')`, and the dashboard JS posts to
`ajax_url + '/dash/' + data_type`.

**Migrate to `worldmap`.** A row set to `world-map` posts to a route that does not
exist and renders an error panel.

## Procedure

### 1. Back up the affected rows

```sql
SELECT * FROM users_widgets WHERE widget = 'group-world-map';
```

Save the output before continuing.

### 2. Verify the target route exists

```bash
php artisan route:list --path=ajax/dash | grep worldmap
```

Must show `ajax/dash/worldmap`. If it does not, **stop** and check the LibreNMS version.

### 3. Migrate

```sql
UPDATE users_widgets
SET widget = 'worldmap'
WHERE widget = 'group-world-map';
```

### 4. Verify

```sql
SELECT user_widget_id, widget, title, settings
FROM users_widgets
WHERE widget IN ('worldmap', 'group-world-map');
```

No row should remain as `group-world-map`, and `title`/`settings` must be unchanged.
Load the affected dashboards and confirm each map renders, is centred as before, and is
still filtered to its device group.

The statement is idempotent — re-running it affects zero rows.

### 5. Clean up the host

Once verified, delete the now-dead controller so a future export does not resurrect it:

```
app/Http/Controllers/Widgets/GroupWorldMapController.php
```

It is untracked in git, so removing it changes nothing tracked. There is no blade or
settings blade to remove — it never had any.

## Running it as a migration instead

`database/migrations-optional/` contains the same change as a Laravel migration. It is
**not** loaded by the service provider, deliberately: mutating core's `users_widgets`
table silently on plugin install is a surprising side effect.

To use it anyway, copy the file into `database/migrations/` inside this package and add
`$this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');` to the service
provider's `boot()`. Note that its `down()` is intentionally a no-op: reversing would
recreate rows pointing at a widget this bundle does not provide.

## Historical note

`group-world-map` was never registered in either `routes/web.php` backup, and the
captured `route:list` shows no route for it. Unlike the other six widgets — which lost
their routes in the 26.8.1 upgrade — this one appears to have been unroutable for
longer, so its placements were probably already showing an error panel. This migration
restores function rather than removing it.
