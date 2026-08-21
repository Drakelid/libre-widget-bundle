# Future Widget Spec — ISP Gap Analysis

Candidate widgets to extend this bundle, chosen by looking at what an ISP NOC needs
against what LibreNMS already collects and what the current six widgets and LibreNMS
core already show.

**Status: sections 3, 4, 5 and 6 were implemented in 1.2.0.** What remains unbuilt is
capacity planning (§6, high effort) and the deployment-conditional widgets (§7).

This document is kept as the rationale behind those widgets and as the shortlist for
what comes next. Data sources were verified against the LibreNMS 26.8.1 schema, effort
estimated, and open questions flagged. Read [`SPEC.md`](SPEC.md) first — it documents how widget discovery works and
the constraints any new widget in this bundle must satisfy.

---

## 1. What is already covered

Do not duplicate these.

| Covered by | Widgets |
|---|---|
| **This bundle** | availability per device group, traffic hotspots, uplink capacity, chassis temperature, link/device flapping, inventory churn |
| **LibreNMS core** | `alerts`, `alertlog`, `alertlog-stats`, `alert-map`, `availability-map`, `component-status`, `custom-map`, `device-summary`, `device-types`, `eventlog`, `generic-graph`, `generic-image`, `globe`, `graylog`, `health-sensors`, `notes`, `server-stats`, `syslog`, `top-devices`, `top-errors`, `top-interfaces`, `world-map` |

In particular: **port errors are already covered** by core's `top-errors`, and generic
sensor display by core's `health-sensors`. A new widget must earn its place by being
more specific than those, not by restating them.

---

## 2. The gaps

The current six cover availability, traffic, capacity, environment, stability and
inventory. For an ISP the notable absences are **optical**, **routing** and **power**.

---

## 3. Priority 1 — Optical Light Levels (DDM)

> **Built in 1.2.0** as `optical-light-levels`.

**Proposed slug:** `optical-light-levels`

### Why this one first

The network is fibre. LibreNMS already collects digital diagnostics, and nothing
displays them — the bundle's `top-device-temperatures` widget explicitly *excludes*
transceiver sensors (`sensors.group = 'transceiver'`), so this data is polled and then
discarded.

Degrading receive power is the best early warning available. A dirty connector, a
bending fibre or an ageing SFP drifts measurably for days before the link drops.
Surfacing it converts an out-of-hours callout into scheduled maintenance.

### Data — verified present in 26.8.1

- `sensors` where `sensor_class = 'dbm'`, `sensor_deleted = 0`.
  Reuse the existing pipeline: `Sensor::hasAccess()`, `sensor_current`, `sensor_limit`,
  `sensor_limit_low`, `sensor_limit_warn`, `sensor_limit_low_warn`.
  Note **low limits matter most here** — the current temperature widget only uses the
  high side.
- `transceivers` table: `device_id`, `port_id`, `index`, `type`, `vendor`, `model`,
  `serial`, `wavelength`, `distance`, `channels`, `ddm`, `connector`, `cable`.
  Join on `device_id` + `entity_physical_index` (the pattern core uses in
  `app/View/Components/TransceiverSensors.php`).
- `ports` for `ifName` / `ifAlias`, so a reading can be named as a real circuit.

### Proposed settings

| Key | Default | Notes |
|---|---|---|
| `title` | `null` | |
| `device_groups` | `[]` | multi, empty = all accessible |
| `sensor_count` | `20` | |
| `mode` | `'worst_margin'` | `worst_margin`, `rx_only`, `tx_only`, `all` |
| `warn_margin_db` | `3` | dB above the low limit at which to warn |
| `include_regex` / `exclude_regex` | `''` | reuse `SafeRegex` |
| `show_transceiver_details` | `true` | vendor / model / wavelength |
| `only_with_limits` | `true` | ignore optics that report no thresholds |

### Behaviour

Margin = `sensor_current - sensor_limit_low`. Rank ascending — smallest margin is the
link closest to failing. Status: critical at or below `sensor_limit_low`, warning within
`warn_margin_db` of it, otherwise ok. Optics with no low limit are `unknown` and sort
last unless `only_with_limits` is off.

Show RX and TX as a pair per port where both exist; a healthy TX with a collapsing RX is
the classic signature of a problem in the fibre path rather than in the local optic.

### Effort — **medium**

Same shape as `top-device-temperatures`: sensor query, threshold classification, meter
rendering. Reuses `SafeRegex`, `Cast`, `Format` and the chunked streaming pattern. The
new work is the `transceivers` join and margin arithmetic.

### Open question

Do the deployed optics actually report DDM? Cheaper SFPs often do not. Settle it with:

```sql
SELECT COUNT(*) AS dbm_sensors FROM sensors WHERE sensor_class='dbm' AND sensor_deleted=0;
SELECT COUNT(*) AS transceivers, SUM(ddm IS NOT NULL) AS with_ddm FROM transceivers;
```

If `dbm_sensors` is near zero, this widget is not worth building.

---

## 4. Priority 2 — BGP Session Health

> **Built in 1.2.0** as `bgp-session-health`.

**Proposed slug:** `bgp-session-health`

### Why

Transit, peering and the DMVPN overlay all depend on BGP. Core ships a BGP *page* but no
widget, so session state cannot appear on a NOC dashboard at all. "Which sessions are not
Established, and which have only just come back" is front-page information for an ISP.

### Data — verified present in 26.8.1

`bgpPeers`: `device_id`, `astext`, `bgpPeerIdentifier`, `bgpPeerRemoteAs`,
`bgpPeerState`, `bgpPeerAdminStatus`, `bgpPeerLastErrorText`, `bgpPeerDescr`,
`bgpPeerIface`, `bgpLocalAddr`, `bgpPeerRemoteAddr`, `bgpPeerFsmEstablishedTime`,
`bgpPeerInUpdates`, `bgpPeerOutUpdates`, `vrf_id`.

`bgpPeers_cbgp` for prefix counts: `AcceptedPrefixes`, `AdvertisedPrefixes`,
`DeniedPrefixes`, `PrefixAdminLimit`, `PrefixThreshold`, plus `*_delta` and `*_prev`
columns — the deltas make "prefix count dropped sharply" detectable without RRD.

### Proposed settings

| Key | Default | Notes |
|---|---|---|
| `title` | `null` | |
| `device_groups` | `[]` | |
| `show` | `'problems'` | `problems`, `all`, `established_only` |
| `recent_flap_minutes` | `60` | flag sessions whose `bgpPeerFsmEstablishedTime` is under this |
| `show_prefixes` | `true` | from `bgpPeers_cbgp` |
| `prefix_drop_percent` | `20` | warn when `AcceptedPrefixes_delta` falls by more than this |
| `limit` | `25` | |

### Behaviour

Rank: admin-up but not Established first, then recently re-established, then large prefix
drops, then healthy. Show `astext` (AS name) rather than only the AS number, and surface
`bgpPeerLastErrorText` — it usually says exactly why a session dropped.

**Note the units:** `bgpPeerFsmEstablishedTime` is seconds since the session came up,
not a timestamp.

### Effort — **low to medium**

Plain table queries, no RRD, no sensor scaling. The simplest of the three.

### Open question

Is the `bgp-peers` discovery module enabled?

```sql
SELECT COUNT(*) AS peers, SUM(bgpPeerState='established') AS up FROM bgpPeers;
```

An empty table means this widget has nothing to show.

---

## 5. Priority 3 — Site Power and Battery

> **Built in 1.2.0** as `site-power-status`.

**Proposed slug:** `site-power-status`

### Why

The estate includes Eltek Flatpack rectifiers and UPS units, many in unmanned huts and
cabinets. Core's `health-sensors` widget requires hand-picking individual sensors and
gives no site-level view. During a regional power event, "which sites are on battery and
how long have they got" is the only screen that matters.

### Data — verified present in 26.8.1

`sensors`, using classes confirmed in `LibreNMS\Enum\Sensor`:
`charge`, `runtime`, `voltage`, `current`, `power`, `state`, `load`, `frequency`.

`state` sensors resolve through `sensors.translations` / `state_indexes` — core's
`Sensor::currentTranslation()` and `stateIndex()` already do this, so mains-vs-battery
status can be read as text rather than as a magic integer.

### Proposed settings

| Key | Default | Notes |
|---|---|---|
| `title` | `null` | |
| `device_groups` | `[]` | |
| `show` | `'problems'` | `problems`, `all` |
| `min_runtime_minutes` | `30` | critical below this |
| `min_charge_percent` | `50` | warning below this |
| `voltage_low` / `voltage_high` | `null` | optional DC rail bounds |
| `group_by` | `'device'` | `device` or `location` — a site view needs `location` |

### Behaviour

One row per device (or per location), summarising the worst condition found: on battery,
runtime remaining, charge %, DC voltage out of range. Sort by severity, then by
remaining runtime ascending — the site about to go dark first appears first.

### Effort — **medium**

Multi-class sensor aggregation and state translation are new; everything else reuses the
temperature widget's structure.

### Open question

Which classes do the rectifiers actually populate?

```sql
SELECT sensor_class, COUNT(*) FROM sensors WHERE sensor_deleted=0
GROUP BY sensor_class ORDER BY 2 DESC;
```

---

## 6. Second tier

> **Customer Port Status and Poller Health were built in 1.2.0** as
> `customer-port-status` and `poller-health`. Capacity planning remains unbuilt.

### Customer Port Status — `customer-port-status`

Ports where `ifAdminStatus = 'up'` and `ifOperStatus = 'down'`, filtered by an alias
regex. Existing widget settings already reference `Kundeport`, so customer-facing ports
are evidently tagged in `ifAlias` — that convention is all this needs.

Directly answers "which customers are down right now", which neither `top-errors` nor
`top-interfaces` addresses. **Effort: low** — the same query shape as the uplink widget
with simpler maths. Add `down_for_minutes` from the eventlog so brief blips can be
filtered out.

### Poller Health / Stale Data — `poller-health`

`devices.last_polled` plus the `poller_cluster` table (`poller_name`, `last_report`,
`poller_enabled`, `poller_workers`, `poller_frequency`).

At 2000+ devices a silently stale dashboard is more dangerous than a blank one: an
availability widget showing all-green because polling stopped an hour ago is actively
misleading. Show devices not polled within N intervals, and pollers whose `last_report`
has gone quiet. **Effort: low.**

### Capacity Planning — 95th percentile growth

The genuine "when do we upgrade this transport link" question. Requires reading RRD via
`rrdtool xport` per port, which is expensive — a 1156-uplink estate cannot do this
synchronously inside a widget refresh.

**Effort: high.** Would need a cache table and a scheduled job, which takes this beyond a
pure display plugin. Worth doing eventually; not worth doing next.

---

## 7. Conditional on deployment

Only worth building if the underlying feature is in use. Check first.

| Widget | Table | Check |
|---|---|---|
| Service check status | `services` (`service_status`, `service_type`, `service_message`, `service_disabled`) | `SELECT COUNT(*) FROM services;` |
| MPLS LSP status | `mpls_lsps` (`mplsLspOperState`, `mplsLspAdminState`, `mplsLspTransitions`, `mplsLspName`) | `SELECT COUNT(*) FROM mpls_lsps;` |
| Wireless / Meraki | `wireless_sensors` (own table, own classes) | `SELECT sensor_class, COUNT(*) FROM wireless_sensors GROUP BY 1;` |

`mpls_lsps.mplsLspTransitions` is a useful stability signal — an LSP that keeps
re-signalling points at an unstable underlay.

---

## 8. Recommended order

1. **Optical Light Levels** — highest operational value on a fibre network, data already
   collected and currently discarded, reuses the existing sensor pipeline.
2. **BGP Session Health** — cheapest of the three, no core equivalent.
3. **Site Power and Battery** — highest value during an incident, moderate new work.
4. Customer Port Status, then Poller Health.
5. Capacity planning only when there is appetite for a cache table and a scheduled job.

---

## 9. Constraints for anything added here

These are not optional. Both 1.0.0 defects were in plugin *registration*, and both took
the production UI down rather than merely breaking a widget.

1. **Dashboards complement alerting, they do not replace it.** BGP down and battery
   depletion should already raise alert rules. These widgets are for situational
   awareness during an incident; do not argue for them as a substitute.
2. **New slugs are permanent.** They are stored in `users_widgets.widget`. Pick them once.
3. **Every query is access-scoped** — `hasAccess()` on devices, ports and sensors, and
   `DeviceGroups::accessibleIds()` on group ids. Group *names* too.
4. **Stream, do not materialise.** Follow the `chunkById` pattern in the uplink and
   temperature widgets. No `->get()` over an unbounded set.
5. **Reuse `Support/`** — `Cast`, `Format`, `SafeRegex`, `DeviceGroups`,
   `BundleWidgetController`. New shared behaviour goes there, not into a controller.
6. **Add the class to `tests/load-check.php`** with its contract, and unit-test any new
   `Support/` code.
7. **CI must be green before tagging.** A broken release of this package does not break a
   widget, it breaks LibreNMS.
8. **Test instance before production.** Every defect so far surfaced only when the code
   was first executed.

---

## 10. Verification queries

Run these against the production database and record the results before committing to
any of the above. Each one can kill a proposal outright.

```sql
-- Optical: is DDM actually reported?
SELECT COUNT(*) AS dbm_sensors FROM sensors WHERE sensor_class='dbm' AND sensor_deleted=0;
SELECT COUNT(*) AS transceivers, SUM(ddm IS NOT NULL) AS with_ddm FROM transceivers;

-- BGP: is peer discovery enabled?
SELECT COUNT(*) AS peers, SUM(bgpPeerState='established') AS established FROM bgpPeers;
SELECT COUNT(*) AS with_prefix_counts FROM bgpPeers_cbgp;

-- Power: which sensor classes do the rectifiers and UPS units populate?
SELECT sensor_class, COUNT(*) AS n FROM sensors WHERE sensor_deleted=0
GROUP BY sensor_class ORDER BY n DESC;

-- Customer ports: does the ifAlias convention hold?
SELECT COUNT(*) AS customer_ports FROM ports WHERE ifAlias REGEXP 'Kundeport';

-- Conditional features
SELECT COUNT(*) AS services FROM services;
SELECT COUNT(*) AS lsps FROM mpls_lsps;
SELECT COUNT(*) AS wireless FROM wireless_sensors;
```
