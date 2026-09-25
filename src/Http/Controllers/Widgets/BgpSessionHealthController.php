<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\BgpPeer;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * BGP session state across transit, peering and any overlay (DMVPN) sessions.
 *
 * LibreNMS ships a BGP page but no widget, so session state cannot reach a dashboard.
 * For an ISP "which sessions are not Established, and which have only just come back"
 * is front-page information during an incident.
 *
 * This complements alerting rather than replacing it: a peer going down should already
 * raise an alert rule. The widget is for situational awareness while working it.
 */
class BgpSessionHealthController extends BundleWidgetController
{
    protected string $name = 'bgp-session-health';

    public const SHOW_MODES = ['problems', 'all', 'established_only'];

    private const CHUNK_SIZE = 500;

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        'show' => 'problems',
        'recent_flap_minutes' => 60,
        'show_prefixes' => true,
        'prefix_drop_percent' => 20,
        'limit' => 25,
        'remote_as' => '',
        'description_filter' => '',
        'vrf_filter' => '',

        // Visible columns. null means "never configured", which falls back to the
        // defaults in Support\Columns (and to any legacy show_* toggles).
        'columns' => null,

        // Layout and styling, shared by every widget in the bundle.
        // PRESENTATION_DEFAULTS -- values come from Presentation::defaults().
        'layout' => 'auto',
        'density' => 'comfortable',
        'accent' => 'default',
        'zebra' => '0',
        'show_header' => '1',
        'card_min_width' => 220,
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['show'] = Cast::choice($settings['show'] ?? 'problems', self::SHOW_MODES, 'problems');
        $settings['recent_flap_minutes'] = Cast::clampedInt($settings['recent_flap_minutes'] ?? 60, 0, 10080, 60);
        $settings['show_prefixes'] = Cast::bool($settings['show_prefixes'] ?? true, true);
        $settings['prefix_drop_percent'] = Cast::clampedFloat($settings['prefix_drop_percent'] ?? 20, 0, 100, 20);
        $settings['limit'] = Cast::clampedInt($settings['limit'] ?? 25, 1, 200, 25);
        $settings['remote_as'] = Cast::nullableString($settings['remote_as'] ?? null) ?? '';
        $settings['description_filter'] = Cast::nullableString($settings['description_filter'] ?? null) ?? '';
        $settings['vrf_filter'] = Cast::nullableString($settings['vrf_filter'] ?? null) ?? '';
        foreach (['remote_as', 'vrf_filter'] as $numericFilter) {
            if ($settings[$numericFilter] !== '' && ! ctype_digit($settings[$numericFilter])) {
                $settings[$numericFilter] = '-1'; // Invalid identifiers must not coerce to VRF/AS 0.
            }
        }

        $settings = Columns::normalize($settings, $this->name);
        $settings = Presentation::normalize($settings, $this->name);

        return $settings;
    }

    public function getView(Request $request): string|View
    {
        $settings = $this->settings();

        // `auto` becomes a concrete layout here, using the widget body size the
        // dashboard posts with every refresh.
        $settings['layout'] = Presentation::resolveLayout($settings, $this->name, $request);
        $settings['widget_classes'] = Presentation::cssClasses($settings, $this->name, $settings['layout']);
        $settings['cols'] = Columns::visible($settings, $this->name);
        $user = $request->user();

        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);

        $query = BgpPeer::hasAccess($user)
            ->with(['device' => fn ($q) => $q->select('device_id', 'hostname', 'sysName', 'status', 'os', 'display', 'last_polled')])
            ->when($settings['remote_as'] !== '', fn ($q) => $q->where('bgpPeerRemoteAs', $settings['remote_as']))
            ->when($settings['vrf_filter'] !== '', fn ($q) => $q->where('vrf_id', $settings['vrf_filter']));

        DeviceGroups::scopeToDevices($query, $groupIds, 'bgpPeers.device_id');

        $stats = ['total' => 0, 'established' => 0, 'down' => 0, 'recent' => 0, 'admin_down' => 0, 'unknown' => 0, 'matched' => 0];
        $rows = [];
        // Established sessions have been up for this many seconds or fewer => recent flap.
        $recentSeconds = $settings['recent_flap_minutes'] * 60;

        $query->chunkById(self::CHUNK_SIZE, function ($peers) use (
            &$rows, &$stats, $settings, $recentSeconds
        ): void {
            $chunkRows = [];
            foreach ($peers as $peer) {
                if ($settings['description_filter'] !== '' && stripos((string) ($peer->bgpPeerDescr ?? ''), $settings['description_filter']) === false) {
                    continue;
                }
                $stats['total']++;

                $admin = strtolower(trim((string) $peer->bgpPeerAdminStatus));
                $established = strtolower(trim((string) $peer->bgpPeerState)) === 'established';

                // 'start' is the standard BGP4-MIB value, 'running' Juniper's.
                $adminUp = in_array($admin, ['start', 'running'], true);

                // Only these mean someone deliberately shut the session. Anything else
                // -- '', 'unknown', null -- means the device did not report an admin
                // status, which is NOT the same as being shut. Core falls back to
                // 'unknown' for Juniper and null for Huawei, so this is common.
                $adminShut = in_array($admin, ['stop', 'halted', 'down', 'disabled'], true);

                // bgpPeerFsmEstablishedTime is SECONDS SINCE the session came up,
                // not a timestamp. A small value means it just re-established.
                $uptime = (int) ($peer->bgpPeerFsmEstablishedTime ?? 0);
                $recent = $established && $recentSeconds > 0 && $uptime > 0 && $uptime <= $recentSeconds;

                if ($established) {
                    $stats['established']++;
                } elseif ($adminUp) {
                    $stats['down']++;
                } elseif ($adminShut) {
                    $stats['admin_down']++;
                } else {
                    $stats['unknown']++;
                }

                if ($recent) {
                    $stats['recent']++;
                }

                /*
                 * An established session is up, whatever the admin field says. Testing
                 * admin status first meant a healthy peer on a device that does not
                 * report bgpPeerAdminStatus was classed 'unknown' and labelled "shut".
                 *
                 * The fault case matches core's BgpPeer::scopeInAlarm(): admin up and
                 * not established. Not established with no admin status is left as
                 * unknown rather than alarmed, which is core's behaviour too.
                 */
                if ($established) {
                    $status = $recent ? 'warning' : 'ok';
                } elseif ($adminUp) {
                    $status = 'critical';
                } else {
                    $status = 'unknown';
                }

                if ($settings['show'] === 'established_only' && ! $established) {
                    continue;
                }

                $chunkRows[] = [
                    'peer' => $peer,
                    'status' => $status,
                    'established' => $established,
                    'admin_up' => $adminUp,
                    'admin_shut' => $adminShut,
                    'state_label' => $peer->bgpPeerState ?: __('unknown'),
                    'uptime_seconds' => $uptime,
                    'recent' => $recent,
                    'prefix' => null,
                    'observed_at' => $peer->device?->last_polled ?? null,
                    'reasons' => $recent ? [__('Recently re-established')] : ($adminUp && ! $established ? [__('Session not established')] : []),
                ];
            }

            // Prefix loss can turn a stable established peer into a problem. Fetch
            // it before filtering or limiting, in batches bounded by CHUNK_SIZE.
            if ($settings['show_prefixes']) {
                $chunkRows = $this->attachPrefixCounts($chunkRows, $settings['prefix_drop_percent']);
            }

            if ($settings['show'] === 'problems') {
                $chunkRows = array_filter($chunkRows, fn (array $row): bool => in_array($row['status'], ['critical', 'warning'], true));
            }
            $stats['matched'] += count($chunkRows);

            $rows = $this->rank(array_merge($rows, $chunkRows), $settings['limit']);
        }, 'bgpPeers.bgpPeer_id', 'bgpPeer_id');

        return view('widgets.bgp-session-health', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'summary' => $stats,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
        ]);
    }

    /** Faults first, then recent flaps, then everything else. */
    private function rank(array $rows, int $limit): array
    {
        $weight = ['critical' => 0, 'warning' => 1, 'ok' => 2, 'unknown' => 3];

        usort($rows, function (array $a, array $b) use ($weight): int {
            $w = ($weight[$a['status']] ?? 9) <=> ($weight[$b['status']] ?? 9);

            if ($w !== 0) {
                return $w;
            }

            // Within a status, the most recently changed session is the interesting one.
            return $a['uptime_seconds'] <=> $b['uptime_seconds'];
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * Prefix counts for one bounded batch of candidate rows.
     *
     * bgpPeers_cbgp carries *_delta and *_prev columns, so a sharp drop in accepted
     * prefixes is detectable without touching RRD.
     *
     * Counts are summed across address families, so a dual-stack peer reads as one
     * session. Context names isolate repeated peer addresses in different VRFs.
     * Older peer rows obtain their SNMP context from the associated vrfs record.
     */
    private function attachPrefixCounts(array $rows, float $dropPercent): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $keys = [];
        $vrfIds = array_values(array_unique(array_filter(array_map(
            fn ($row) => (int) ($row['peer']->vrf_id ?? 0), $rows
        ))));
        $vrfContexts = $vrfIds === [] ? collect() : DB::table('vrfs')
            ->whereIntegerInRaw('vrf_id', $vrfIds)->pluck('context_name', 'vrf_id');

        foreach ($rows as $i => $row) {
            $vrfId = (int) ($row['peer']->vrf_id ?? 0);
            $context = $row['peer']->context_name ?? null;
            if ($context === null || $context === '') {
                $context = $vrfContexts->get($vrfId);
            }
            // A non-default VRF without a context mapping cannot safely borrow
            // the default context's prefixes. Leave its prefix data unavailable.
            $rows[$i]['prefix_context'] = $vrfId > 0 && ($context === null || $context === '') ? null : (string) $context;
            if ($rows[$i]['prefix_context'] !== null) {
                $keys[] = [(int) $row['peer']->device_id, (string) $row['peer']->bgpPeerIdentifier];
            }
        }
        if ($keys === []) {
            return $rows;
        }

        $counts = DB::table('bgpPeers_cbgp')
            ->select('device_id', 'bgpPeerIdentifier', 'context_name', 'afi', 'safi',
                'AcceptedPrefixes', 'AcceptedPrefixes_prev', 'AcceptedPrefixes_delta',
                'AdvertisedPrefixes', 'PrefixAdminLimit')
            ->where(function ($query) use ($keys): void {
                foreach (array_unique($keys, SORT_REGULAR) as [$deviceId, $identifier]) {
                    $query->orWhere(function ($peerQuery) use ($deviceId, $identifier): void {
                        $peerQuery->where('device_id', $deviceId)
                            ->where('bgpPeerIdentifier', $identifier);
                    });
                }
            })
            ->get()
            ->groupBy(fn ($r): string => json_encode([(int) $r->device_id, (string) $r->bgpPeerIdentifier, (string) $r->context_name]));

        foreach ($rows as $i => $row) {
            if ($row['prefix_context'] === null) {
                continue;
            }
            $key = json_encode([(int) $row['peer']->device_id, (string) $row['peer']->bgpPeerIdentifier, $row['prefix_context']]);
            $entries = $counts->get($key);

            if ($entries === null || $entries->isEmpty()) {
                continue;
            }

            // Sum across address families so a dual-stack peer reads as one session.
            $accepted = (int) $entries->sum('AcceptedPrefixes');
            $advertised = (int) $entries->sum('AdvertisedPrefixes');
            $prev = (int) $entries->sum('AcceptedPrefixes_prev');
            $limit = (int) $entries->max('PrefixAdminLimit');

            $dropped = false;

            if ($prev > 0 && $accepted < $prev) {
                $dropped = ((($prev - $accepted) / $prev) * 100) >= $dropPercent;
            }

            $rows[$i]['prefix'] = [
                'accepted' => $accepted,
                'advertised' => $advertised,
                'previous' => $prev,
                'limit' => $limit,
                'dropped' => $dropped,
            ];

            // A collapsing table on an otherwise healthy session is still a fault.
            if ($dropped && $rows[$i]['status'] === 'ok') {
                $rows[$i]['status'] = 'warning';
            }
            if ($dropped) {
                $rows[$i]['reasons'][] = __('Accepted prefixes dropped') . ' ' . round((($prev - $accepted) / $prev) * 100, 1) . '%';
            }
        }

        return $rows;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.bgp-session-health', $settings);
    }
}
