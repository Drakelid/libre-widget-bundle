<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Port;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\Format;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ports ranked by current total throughput, optionally scoped to device groups.
 *
 * Utilisation here is TOTAL based ((in + out) / ifSpeed). The uplink widget uses
 * PEAK instead; the two are deliberately different and must not be unified.
 */
class TopBandwidthDeviceGroupController extends BundleWidgetController
{
    protected string $name = 'top-bandwidth-device-group';

    protected $defaults = [
        'title' => null,
        'top_count' => 10,
        'time_interval' => 15,
        'interface_filter' => null,
        'device_groups' => [],
        'show_graphs' => 1,
        'show_utilisation' => 1,
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['top_count'] = Cast::clampedInt($settings['top_count'] ?? 10, 1, 50, 10);
        $settings['time_interval'] = Cast::clampedInt($settings['time_interval'] ?? 15, 1, 1440, 15);
        $settings['interface_filter'] = Cast::nullableString($settings['interface_filter'] ?? null);
        $settings['show_graphs'] = Cast::bool($settings['show_graphs'] ?? true, true);
        $settings['show_utilisation'] = Cast::bool($settings['show_utilisation'] ?? true, true);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);

        return $settings;
    }

    public function getView(Request $request): string|View
    {
        $settings = $this->settings();
        $user = $request->user();

        // Never trust group ids from the settings blob; a user could hand-edit one in.
        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);

        $query = Port::hasAccess($user)
            ->with(['device' => function ($query): void {
                $query->select('device_id', 'hostname', 'sysName', 'status', 'os', 'display');
            }])
            ->isValid()
            ->isUp()
            ->select([
                'ports.port_id',
                'ports.device_id',
                'ports.ifName',
                'ports.ifDescr',
                'ports.ifAlias',
                'ports.ifType',
                'ports.ifSpeed',
                'ports.ifInOctets_rate',
                'ports.ifOutOctets_rate',
            ])
            ->where('ports.poll_time', '>', Carbon::now()->subMinutes($settings['time_interval'])->timestamp)
            ->when(empty($groupIds), fn ($query) => $query->has('device'))
            ->when($settings['interface_filter'], fn ($query) => $query->where('ports.ifType', '=', $settings['interface_filter']))
            // LEAST() guards against counter overflow producing an absurd rate that
            // would otherwise dominate the ordering. Inherited from core's top-interfaces.
            ->orderByRaw('(LEAST(COALESCE(ports.ifInOctets_rate, 0), 9223372036854775807)'
                . ' + LEAST(COALESCE(ports.ifOutOctets_rate, 0), 9223372036854775807)) DESC')
            ->limit($settings['top_count']);

        DeviceGroups::scopeToDevices($query, $groupIds);

        $ports = $query->get();

        $memberships = DeviceGroups::membershipMap(
            $groupIds,
            $ports->pluck('device_id')->unique()->values()->all()
        );

        // Bars are proportional to the busiest port on screen.
        $peakTotal = 0.0;

        $rows = $ports->map(function (Port $port) use ($memberships, &$peakTotal): array {
            $inBps = Format::octetsToBits($port->ifInOctets_rate);
            $outBps = Format::octetsToBits($port->ifOutOctets_rate);
            $totalBps = $inBps + $outBps;
            $peakTotal = max($peakTotal, $totalBps);

            $utilisation = Format::utilisation($totalBps, (float) ($port->ifSpeed ?? 0));

            return [
                'port' => $port,
                'in_label' => Format::bits($inBps),
                'out_label' => Format::bits($outBps),
                'total_label' => Format::bits($totalBps),
                'total_bps' => $totalBps,
                'utilisation' => $utilisation,
                'utilisation_label' => Format::percent($utilisation),
                'group_names' => $memberships->get($port->device_id, ''),
            ];
        })->all();

        foreach ($rows as $index => $row) {
            $rows[$index]['bar_percent'] = $peakTotal > 0
                ? max(2, round(($row['total_bps'] / $peakTotal) * 100))
                : 0;
        }

        return view('widgets.top-bandwidth-device-group', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All device groups')),
            'has_group_filter' => ! empty($groupIds),
        ]);
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);

        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);
        $settings['interface_types'] = Port::hasAccess($request->user())
            ->whereNotNull('ifType')
            ->where('ifType', '!=', '')
            ->distinct()
            ->orderBy('ifType')
            ->limit(500)
            ->pluck('ifType');

        return view('widgets.settings.top-bandwidth-device-group', $settings);
    }
}
