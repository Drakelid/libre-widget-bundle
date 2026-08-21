# Testing

## Automated (no LibreNMS needed)

The `Support/` layer is deliberately free of Eloquent and framework facades:

```bash
composer install
vendor/bin/phpunit
```

Covers bit-rate/percent/temperature formatting, the deci-Celsius scaling heuristics,
regex compilation and backtracking protection, settings coercion, and device-group id
normalisation against the shapes the live database actually stores.

Everything else — controllers, queries, blades — needs a real instance. **None of the
checks below have been run yet**; they are the acceptance gate for this release.

## Manual acceptance

Run against a LibreNMS 26.8.1 instance with a copy of production data.

### Install

1. `./lnms plugin:add drakelid/librenms-dashboard-widgets`
2. `php artisan route:clear` (mandatory — routes are cached in production)
3. Enable under **Overview → Plugins → Plugins Admin**

### Gate 1 — registration

- [ ] `php artisan route:list --path=ajax/dash` lists all six new routes with prefix
      `ajax/dash`, alongside core's existing widget routes.
- [ ] All six appear in the dashboard **Add Widget** picker, with correct titles.
- [ ] `group-world-map` does **not** appear.
- [ ] The plugin page at **Dashboard Widget Bundle** renders and shows the version.

### Gate 2 — the settings toggle round trip

The highest-risk regression in this port. LibreNMS decides whether a response is the
settings form by string-matching the view name against `widgets.settings.`; a
namespaced plugin view fails that test and leaves the widget stuck in the form.

For **each of the six widgets**:

- [ ] Click the pencil icon → the settings form appears
- [ ] Click the pencil icon again → **the widget content returns**

If the form renders twice, `View::getFinder()->addLocation()` in the service provider is
not taking effect.

### Gate 3 — existing placements

- [ ] Restore `users-widgets.json` (19 rows) into the test database. All 19 render with
      their saved settings intact.
- [ ] The `device-group-down-count` row storing `background_color: "#80ff80"` still uses
      that colour.
- [ ] The row selecting 23 device groups still shows all 23, in the saved order.
- [ ] `top-device-temperatures` rows keep their `outlet|inlet|` include regex, and the
      "matches every sensor" hint appears for it.
- [ ] Settings persist across a page reload and across a widget refresh.
- [ ] Saving a widget's settings twice in a row does not duplicate or drop any field.

### Gate 4 — the map migration

- [ ] Restore `group-world-map-users-widgets.json` (2 rows).
- [ ] Follow `docs/RETIRE-GROUP-WORLD-MAP.md`.
- [ ] Both rows render as core World Map widgets, still titled "Device Group Map", still
      centred on their saved coordinates, still filtered to device groups 1 and 3.
- [ ] Re-running the `UPDATE` affects zero rows.

### Gate 5 — permissions

Use a real restricted account, not a code reading.

- [ ] A non-admin with limited device permissions sees only permitted devices, ports and
      sensors in all six widgets.
- [ ] The device group picker in every settings form lists only permitted groups.
- [ ] Group names shown in widget bodies are only permitted groups.
- [ ] Hand-editing an inaccessible group id into a widget's stored settings does not
      expose that group's data or name.

### Gate 6 — correctness

- [ ] **Multi-group filtering** returns the union of the selected groups. Verify against
      a hand-written SQL count, including a group with more than 1000 devices (this is
      what `whereIntegerInRaw` protects against).
- [ ] **Two utilisation formulas.** Pick a port appearing in both bandwidth widgets with
      asymmetric traffic; Top Bandwidth must show `(in + out) / ifSpeed` and Uplink
      Utilization `max(in, out) / ifSpeed`.
- [ ] **Uplink summary spans all matches.** The "matched uplinks" tile should read far
      higher than the number of rows displayed (~1156 vs 20 on the reference data).
- [ ] **One row per device** in Top Device Temperatures — no device appears twice.
- [ ] **Empty group selection**: `device-group-down-count` shows its explanatory empty
      state; the other group-filtered widgets show all accessible devices.
- [ ] **Down counts** match `SELECT COUNT(*) FROM devices ... WHERE status = 0` for a
      chosen group, with and without `exclude_ignored_disabled`.
- [ ] Run the sensor query in `docs/`/spec §8.4 and confirm the `group = 'transceiver'`
      filter matches what production actually stores. Compare the temperature widget's
      output before/after toggling **Temperature source**.

### Gate 6b — the 1.2.0 ISP widgets

These depend on what the installation collects. Run the data checks FIRST; an empty
result means the widget has nothing to show, not that it is broken.

```sql
SELECT COUNT(*) FROM sensors WHERE sensor_class='dbm' AND sensor_deleted=0;
SELECT COUNT(*) FROM bgpPeers;
SELECT sensor_class, COUNT(*) FROM sensors WHERE sensor_deleted=0 GROUP BY 1 ORDER BY 2 DESC;
SELECT COUNT(*) FROM ports WHERE ifAlias REGEXP 'kundeport|customer|kunde';
```

- [ ] **Optical**: rows are ordered by *ascending* margin — the smallest margin first.
      Spot-check one row: margin must equal `sensor_current - sensor_limit_low`.
- [ ] **Optical**: a reading below its low threshold shows critical; one within the
      warning margin shows warning. Toggling "only show optics that report a low
      threshold" changes the row count.
- [ ] **Optical**: the transceiver column shows vendor/model for at least some rows —
      if always blank, the `device_id` + `entPhysicalIndex` join is not matching.
- [ ] **BGP**: tile counts add up (`established + down + admin_down` = total). A session
      that is administratively shut shows as `shut`, not as a fault.
- [ ] **BGP**: uptime reads as a sensible duration. `bgpPeerFsmEstablishedTime` is
      seconds since establishment; if it renders as a date in 1970, it is being treated
      as a timestamp.
- [ ] **Power**: switching group-by between device and location changes the row count.
      Devices with no location must not collapse into one fake site.
- [ ] **Power**: a device reporting a `state` sensor with `state_generic_value` of 2
      shows critical. Verify against one real sensor.
- [ ] **Customer ports**: every row is genuinely admin-up and oper-down. Compare with
      `SELECT COUNT(*) FROM ports WHERE ifAdminStatus='up' AND ifOperStatus='down'`.
- [ ] **Customer ports**: "down for" shows a dash rather than a wrong duration where
      `ifLastChange` is unusable (it is relative to device uptime and resets on reboot).
- [ ] **Poller health**: stop a poller, or set the stale threshold below the polling
      interval, and confirm devices appear. Confirm the counts sum to the device total.

### Gate 6c — Device Group Down Count layouts (1.3.0)

- [ ] Each of the seven display modes renders its own distinct layout: auto, list,
      cards, bars, tiles, compact, summary.
- [ ] **Auto** switches layout as the widget is resized: summary when very narrow,
      list at moderate width, cards when wide.
- [ ] **Bars**: bar length reflects the *proportion* down, not the raw count. A group
      with 2 of 2 down must show a longer bar than one with 22 of 500.
- [ ] **Tiles**: long group names truncate to two lines without breaking the square.
- [ ] **Hide healthy** removes rows but leaves the header and hero totals unchanged.
- [ ] **Sort** options reorder correctly; "the order I selected them" still matches the
      chip order in the settings form.
- [ ] The alert colour settings still drive the hero when devices are down.
- [ ] A single selected group shows the hero with that group's own totals and no
      redundant one-row list beneath it.
- [ ] Saved widgets from before 1.3.0 render unchanged, except `compact` which is
      intentionally a new layout.

### Gate 7 — resilience

- [ ] Invalid regex (e.g. `uplink(`) in the Uplink and Temperature widgets produces an
      inline warning, not a 500 and not a silently empty widget.
- [ ] A catastrophic pattern (e.g. `(a+)+$`) degrades with a warning rather than hanging.
- [ ] Deleting a device group referenced by a `flapping-devices` widget does not fatal;
      the widget falls back to all devices.
- [ ] Disabling the plugin removes the widgets from the picker, and dashboards still
      referencing them render an error panel rather than a 500.
- [ ] No PHP notices or warnings in `storage/logs/laravel.log` after exercising all six.

### Gate 8 — presentation

- [ ] Each widget renders correctly at roughly 250px, 500px and 1200px wide.
- [ ] Both light and dark themes are legible; no hardcoded colour looks wrong in either.
- [ ] Two copies of the same widget on one dashboard behave independently — settings
      forms edit the right widget and labels focus the right inputs.
- [ ] `<style id="nmsdw-styles">` appears exactly once in `<head>`, regardless of how
      many bundle widgets are on the dashboard.
- [ ] Wide tables scroll inside the widget body; the dashboard itself never scrolls
      horizontally.

### Gate 9 — performance

Measure and record; do not just eyeball.

- [ ] Each widget's JSON response completes in under 500 ms on production data.
- [ ] The Uplink widget in particular — it previously loaded every accessible port into
      memory. Compare PHP peak memory against the old implementation.
- [ ] A dashboard holding all six widgets refreshes without a visible stall.
