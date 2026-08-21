<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\BgpPeer;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
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
        $user = $request->user();

        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);

        $query = BgpPeer::hasAccess($user)
            ->with(['device' => fn ($q) => $q->select('device_id', 'hostname', 'sysName', 'status', 'os', 'display')]);

        DeviceGroups::scopeToDevices($query, $groupIds, 'bgpPeers.device_id');

        $stats = ['total' => 0, 'established' => 0, 'down' => 0, 'recent' => 0, 'admin_down' => 0];
        $rows = [];
        // Established sessions have been up for this many seconds or fewer => recent flap.
        $recentSeconds = $settings['recent_flap_minutes'] * 60;

        $query->chunkById(self::CHUNK_SIZE, function ($peers) use (
            &$rows, &$stats, $settings, $recentSeconds
        ): void {
            foreach ($peers as $peer) {
                $stats['total']++;

                $adminUp = in_array(strtolower((string) $peer->bgpPeerAdminStatus), ['start', 'running'], true);
                $established = strtolower((string) $peer->bgpPeerState) === 'established';

                // bgpPeerFsmEstablishedTime is SECONDS SINCE the session came up,
                // not a timestamp. A small value means it just re-established.
                $uptime = (int) ($peer->bgpPeerFsmEstablishedTime ?? 0);
                $recent = $established && $recentSeconds > 0 && $uptime > 0 && $uptime <= $recentSeconds;

                if ($established) {
                    $stats['established']++;
                } elseif ($adminUp) {
                    $stats['down']++;
                } else {
                    $stats['admin_down']++;
                }

                if ($recent) {
                    $stats['recent']++;
                }

                if ($established && ! $adminUp) {
                    $status = 'unknown';
                } elseif (! $established && $adminUp) {
                    $status = 'critical';
                } elseif (! $adminUp) {
                    $status = 'unknown';   // administratively shut: not a fault
                } elseif ($recent) {
                    $status = 'warning';
                } else {
                    $status = 'ok';
                }

                if ($settings['show'] === 'established_only' && ! $established) {
                    continue;
                }

                if ($settings['show'] === 'problems' && in_array($status, ['ok', 'unknown'], true)) {
                    continue;
                }

                $rows[] = [
                    'peer' => $peer,
                    'status' => $status,
                    'established' => $established,
                    'admin_up' => $adminUp,
                    'uptime_seconds' => $uptime,
                    'recent' => $recent,
                    'prefix' => null,
                ];
            }
        }, 'bgpPeers.bgpPeer_id', 'bgpPeer_id');

        $rows = $this->rank($rows, $settings['limit']);

        if ($settings['show_prefixes']) {
            $rows = $this->attachPrefixCounts($rows, $settings['prefix_drop_percent']);
        }

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
     * Prefix counts for the displayed rows only.
     *
     * bgpPeers_cbgp carries *_delta and *_prev columns, so a sharp drop in accepted
     * prefixes is detectable without touching RRD.
     */
    private function attachPrefixCounts(array $rows, float $dropPercent): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $keys = [];

        foreach ($rows as $row) {
            $keys[] = [(int) $row['peer']->device_id, (string) $row['peer']->bgpPeerIdentifier];
        }

        $counts = DB::table('bgpPeers_cbgp')
            ->select('device_id', 'bgpPeerIdentifier', 'afi', 'safi',
                'AcceptedPrefixes', 'AcceptedPrefixes_prev', 'AcceptedPrefixes_delta',
                'AdvertisedPrefixes', 'PrefixAdminLimit')
            ->whereIntegerInRaw('device_id', array_values(array_unique(array_column($keys, 0))))
            ->get()
            ->groupBy(fn ($r): string => $r->device_id . ':' . $r->bgpPeerIdentifier);

        foreach ($rows as $i => $row) {
            $key = $row['peer']->device_id . ':' . $row['peer']->bgpPeerIdentifier;
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
        }

        return $rows;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);

        return view('widgets.settings.bgp-session-health', $settings);
    }
}
