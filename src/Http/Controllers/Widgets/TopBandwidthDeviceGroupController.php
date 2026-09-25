<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Port;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\Format;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ports ranked by current total throughput, optionally scoped to device groups.
 *
 * Full-duplex utilisation uses the busier direction; combined traffic is throughput.
 */
class TopBandwidthDeviceGroupController extends BundleWidgetController
{
    protected string $name = 'top-bandwidth-device-group';

    protected $defaults = [
        'title' => null,
        'top_count' => 10,
        'time_interval' => 15,
        'interface_filter' => null,
        'sort_by' => 'combined',
        'interface_scope' => 'all',
        'device_groups' => [],
        'show_graphs' => 1,
        'show_utilisation' => 1,

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
        $settings['top_count'] = Cast::clampedInt($settings['top_count'] ?? 10, 1, 50, 10);
        $settings['time_interval'] = Cast::clampedInt($settings['time_interval'] ?? 15, 1, 1440, 15);
        $settings['interface_filter'] = Cast::nullableString($settings['interface_filter'] ?? null);
        $settings['sort_by'] = Cast::choice($settings['sort_by'] ?? 'combined', ['rx', 'tx', 'combined', 'utilisation'], 'combined');
        $settings['interface_scope'] = Cast::choice($settings['interface_scope'] ?? 'all', ['all', 'physical', 'aggregate'], 'all');
        $settings['show_graphs'] = Cast::bool($settings['show_graphs'] ?? true, true);
        $settings['show_utilisation'] = Cast::bool($settings['show_utilisation'] ?? true, true);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);

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
                // ifIndex is not displayed directly: Port::getLabel() appends it on
                // OSes configured with the 'ifindex' setting, and x-port-link calls that.
                'ports.ifIndex',
                'ports.ifDescr',
                'ports.ifAlias',
                'ports.ifType',
                'ports.ifSpeed',
                'ports.ifInOctets_rate',
                'ports.ifOutOctets_rate',
                'ports.poll_time',
            ])
            ->where('ports.poll_time', '>', Carbon::now()->subMinutes($settings['time_interval'])->timestamp)
            ->when(empty($groupIds), fn ($query) => $query->has('device'))
            ->when($settings['interface_filter'], fn ($query) => $query->where('ports.ifType', '=', $settings['interface_filter']))
            ->when($settings['interface_scope'] === 'physical', fn ($q) => $q->whereIn('ports.ifType', ['ethernetCsmacd', 'iso88023Csmacd']))
            ->when($settings['interface_scope'] === 'aggregate', fn ($q) => $q->where('ports.ifType', 'ieee8023adLag'));

        DeviceGroups::scopeToDevices($query, $groupIds);

        $matched = (clone $query)->count();
        $rx = 'GREATEST(0, LEAST(COALESCE(ports.ifInOctets_rate, 0), 9223372036854775807))';
        $tx = 'GREATEST(0, LEAST(COALESCE(ports.ifOutOctets_rate, 0), 9223372036854775807))';
        $order = match ($settings['sort_by']) {
            'rx' => $rx,
            'tx' => $tx,
            'utilisation' => "CASE WHEN ports.ifSpeed > 0 THEN GREATEST($rx, $tx) * 800.0 / ports.ifSpeed ELSE -1 END",
            default => "($rx + $tx)",
        };
        $ports = $query->orderByRaw($order . ' DESC')->orderBy('ports.port_id')->limit($settings['top_count'])->get();

        $memberships = DeviceGroups::membershipMap(
            $groupIds,
            $ports->pluck('device_id')->unique()->values()->all(),
            $user
        );

        // Bars are proportional to the busiest port on screen.
        $peakTotal = 0.0;

        $rows = $ports->map(function (Port $port) use ($memberships, &$peakTotal, $settings): array {
            $inBps = Format::octetsToBits($port->ifInOctets_rate);
            $outBps = Format::octetsToBits($port->ifOutOctets_rate);
            $totalBps = $inBps + $outBps;
            $utilisation = Format::utilisation(max($inBps, $outBps), (float) ($port->ifSpeed ?? 0));
            $rankValue = match ($settings['sort_by']) {
                'rx' => $inBps, 'tx' => $outBps, 'utilisation' => $utilisation ?? 0,
                default => $totalBps,
            };
            $peakTotal = max($peakTotal, $rankValue);

            return [
                'port' => $port,
                'in_label' => Format::bits($inBps),
                'out_label' => Format::bits($outBps),
                'total_label' => Format::bits($totalBps),
                'total_bps' => $totalBps,
                'rank_value' => $rankValue,
                'rank_label' => $settings['sort_by'] === 'utilisation' ? Format::percent($utilisation) : Format::bits($rankValue),
                'observed_at' => $port->poll_time,
                'rx_utilisation' => Format::percent(Format::utilisation($inBps, (float) $port->ifSpeed)),
                'tx_utilisation' => Format::percent(Format::utilisation($outBps, (float) $port->ifSpeed)),
                'utilisation' => $utilisation,
                'utilisation_label' => Format::percent($utilisation),
                'group_names' => $memberships->get($port->device_id, ''),
            ];
        })->all();

        foreach ($rows as $index => $row) {
            $rows[$index]['bar_percent'] = $peakTotal > 0
                ? max(2, round(($row['rank_value'] / $peakTotal) * 100))
                : 0;
        }

        return view('widgets.top-bandwidth-device-group', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'matched_total' => $matched,
            'sort_label' => match ($settings['sort_by']) { 'rx' => __('RX throughput'), 'tx' => __('TX throughput'), 'utilisation' => __('Peak directional utilisation'), default => __('Combined throughput') },
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All device groups')),
            'has_group_filter' => ! empty($groupIds),
        ]);
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);

        // Interface types are not enumerated here on purpose: the form uses core's
        // ajax/select/port-field source, which looks them up on demand. Building the
        // list server side meant a DISTINCT over the whole ports table every time
        // someone opened the settings panel.
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.top-bandwidth-device-group', $settings);
    }
}
