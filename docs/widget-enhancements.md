# Widget enhancements

All existing widget identifiers and saved settings remain supported. Open a
widget's settings to choose its new filters and optional historical checks.

| Widget | Enhancements |
|---|---|
| Device Group Down Count | Optional unique-device totals, explicit overlapping group membership, maintenance versus unexpected outages. |
| Top Bandwidth | RX, TX, combined traffic or peak utilization ranking; physical Ethernet or aggregate scope; full-duplex utilization uses the busiest direction. |
| Uplink Utilization | RX/TX remaining capacity, busiest direction, optional sustained congestion window. |
| Top Temperatures | Rank by distance to each sensor's own limit, widget fallback limits, expandable device sensors, optional 24-hour change. |
| Flapping | Current polled state separate from logged state, device/interface/event links, bounded transition details, full-match summary. |
| Recently Added | Group and added-within filters, first polling/discovery progress, location and reachability. |
| Optical Light Levels | Conservative RX/TX interface/lane pairing, exclusion counts, graph links, optional 24-hour change. Only active interfaces on online devices qualify. Ambiguous lanes remain separate. |
| BGP | AS, description and VRF filters, explicit warning reasons, separate administratively shut/unknown counts, prefix counts isolated by VRF context. |
| Site Power | Exact alarm sensor/device, member details, voltage minimum and maximum, reported battery states separate from low-reserve inference. |
| Customer Ports | Device/site grouping, duration buckets, reported circuit aliases and offline-parent labels. Aliases are not guessed customer identities. |
| Offline Map | One authorized snapshot for map and outage list, richer popups, missing-coordinate count, fit-current-outages control. Outage duration is explicitly estimated from the last successful poll. |
| Poller Health | Poller-group summaries, disabled versus failed nodes, effective polling intervals and poll duration overruns. |

Shared layouts preserve units, status text, selected details and navigation.
Widgets show observation age and displayed versus matching counts where applicable.
Sensor widgets identify device polling time as a freshness proxy: LibreNMS updates
the sensor's `lastupdate` only when its value changes, so that timestamp cannot
prove whether an unchanged reading is stale.

Regex-based widgets offer **Preview filters**. It checks unsaved regex and group
choices, reports the full count and at most five examples, and never saves changes.
Other filters use the saved widget settings. Invalid patterns and unavailable
groups receive explicit feedback.

History is optional and disabled by default. It reads existing LibreNMS RRD files
or the configured rrdcached service through RRDtool, caches results for five minutes,
and limits each request to 20 uncached reads and a five-second budget (two seconds
per read). Only displayed rows request history. Missing, incomplete or unavailable
history is shown as unavailable, never as a healthy zero. Congestion requires all
covered intervals to meet the threshold; sensor change requires a sufficiently
complete window. No new collection or database migration is required.

See [testing instructions](../TESTING.md) for automated checks and the real-host
rendering harness. Live database, browser and RRD integration still need validation
on a configured LibreNMS test installation.
