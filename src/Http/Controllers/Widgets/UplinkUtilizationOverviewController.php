<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Port;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\Format;
use Drakelid\NmsDashWidgets\Support\SafeRegex;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Interfaces matching an "uplink-like" regex, ranked by utilisation.
 *
 * Two things here are easy to get wrong and are covered by unit tests:
 *
 *  1. Utilisation is PEAK based -- max(in, out) / ifSpeed -- not total based like the
 *     top-bandwidth widget. A 1 Gbps link pushing 142 Mbps out reads 14.3%.
 *  2. The summary tiles describe the WHOLE matched set, not the rows on screen. The
 *     reference install matches ~1156 uplinks while displaying 20.
 *
 * The original loaded every accessible up port into memory before filtering. This
 * version streams in chunks and keeps only a bounded top-N, so memory stays flat
 * regardless of port count.
 */
class UplinkUtilizationOverviewController extends BundleWidgetController
{
    protected string $name = 'uplink-utilization-overview';

    /** Rows pulled from the database per round trip. */
    private const CHUNK_SIZE = 1000;

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        'uplink_regex' => 'uplink|upstream|trunk|wan|core|backbone|transport',
        'exclude_regex' => '',
        'top_count' => 20,
        'time_interval' => 15,
        'warning_threshold' => 70,
        'critical_threshold' => 90,
        'show_graphs' => 1,
        'show_device_group' => 1,
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['top_count'] = Cast::clampedInt($settings['top_count'] ?? 20, 1, 100, 20);
        $settings['time_interval'] = Cast::clampedInt($settings['time_interval'] ?? 15, 1, 1440, 15);
        $settings['show_graphs'] = Cast::bool($settings['show_graphs'] ?? true, true);
        $settings['show_device_group'] = Cast::bool($settings['show_device_group'] ?? true, true);

        $warning = Cast::clampedFloat($settings['warning_threshold'] ?? 70, 1, 100, 70);
        $critical = Cast::clampedFloat($settings['critical_threshold'] ?? 90, 1, 100, 90);

        // Preserve the original's correction: a warning threshold at or above critical
        // is nonsensical, so pull it 10 points below rather than rejecting the save.
        if ($warning >= $critical) {
            $warning = max(1, $critical - 10);
        }

        $settings['warning_threshold'] = $warning;
        $settings['critical_threshold'] = $critical;

        return $settings;
    }

    public function getView(Request $request): string|View
    {
        $settings = $this->settings();
        $user = $request->user();

        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);

        $include = SafeRegex::make($settings['uplink_regex']);
        $exclude = SafeRegex::make($settings['exclude_regex']);

        // An empty or unusable include pattern falls back to the default rather than
        // matching nothing, so neither a typo nor a cleared field blanks the widget.
        if ($include->isEmpty() || $include->isInvalid()) {
            $include = SafeRegex::make($this->defaults['uplink_regex']);
        }

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
                'ports.ifSpeed',
                'ports.ifInOctets_rate',
                'ports.ifOutOctets_rate',
            ])
            ->where('ports.poll_time', '>', Carbon::now()->subMinutes($settings['time_interval'])->timestamp);

        DeviceGroups::scopeToDevices($query, $groupIds);

        $stats = [
            'matched' => 0,
            'critical' => 0,
            'warning' => 0,
            'ok' => 0,
            'unknown' => 0,
            'in_bps' => 0.0,
            'out_bps' => 0.0,
            'total_bps' => 0.0,
            'util_sum' => 0.0,
            'util_count' => 0,
            'util_max' => null,
        ];

        $top = [];
        $keep = $settings['top_count'];
        // Let the buffer grow to a few times the display size before re-sorting, so we
        // sort a handful of times rather than once per matched row.
        $highWater = max($keep * 4, 200);

        $query->chunkById(self::CHUNK_SIZE, function ($ports) use (
            &$stats, &$top, $include, $exclude, $settings, $keep, $highWater
        ): void {
            foreach ($ports as $port) {
                $haystack = trim(implode(' ', array_filter([
                    $port->ifName,
                    $port->ifDescr,
                    $port->ifAlias,
                ])));

                if (! $include->matches($haystack)) {
                    continue;
                }

                if ($exclude->isUsable() && $exclude->matches($haystack)) {
                    continue;
                }

                $row = $this->buildRow($port, $settings);

                $stats['matched']++;
                $stats[$row['status']]++;
                $stats['in_bps'] += $row['in_bps'];
                $stats['out_bps'] += $row['out_bps'];
                $stats['total_bps'] += $row['total_bps'];

                if ($row['utilisation'] !== null) {
                    $stats['util_sum'] += $row['utilisation'];
                    $stats['util_count']++;
                    $stats['util_max'] = $stats['util_max'] === null
                        ? $row['utilisation']
                        : max($stats['util_max'], $row['utilisation']);
                }

                $top[] = $row;

                if (count($top) >= $highWater) {
                    $top = $this->trimTop($top, $keep);
                }
            }
        }, 'ports.port_id', 'port_id');

        $rows = $this->trimTop($top, $keep);

        $memberships = DeviceGroups::membershipMap(
            $groupIds,
            array_values(array_unique(array_map(fn (array $r): int => (int) $r['port']->device_id, $rows)))
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['group_names'] = $memberships->get($row['port']->device_id, '');
        }

        return view('widgets.uplink-utilization-overview', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'summary' => $this->summarise($stats),
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
            'effective_regex' => $include->raw(),
            'regex_problems' => $this->regexProblems($include, $exclude, $settings),
        ]);
    }

    /**
     * Reduce the buffer to the highest utilisation rows.
     *
     * Sort order: utilisation descending, ties broken on total throughput. Ports with
     * an unknown ifSpeed have no utilisation and always sort last.
     */
    private function trimTop(array $rows, int $keep): array
    {
        usort($rows, function (array $a, array $b): int {
            $aUtil = $a['utilisation'] ?? -1;
            $bUtil = $b['utilisation'] ?? -1;

            if ($aUtil === $bUtil) {
                return $b['total_bps'] <=> $a['total_bps'];
            }

            return $bUtil <=> $aUtil;
        });

        return array_slice($rows, 0, $keep);
    }

    private function buildRow(Port $port, array $settings): array
    {
        $inBps = Format::octetsToBits($port->ifInOctets_rate);
        $outBps = Format::octetsToBits($port->ifOutOctets_rate);
        $totalBps = $inBps + $outBps;
        $peakBps = max($inBps, $outBps);
        $speedBps = (float) ($port->ifSpeed ?? 0);

        $utilisation = Format::utilisation($peakBps, $speedBps);

        if ($utilisation === null) {
            $status = 'unknown';
        } elseif ($utilisation >= $settings['critical_threshold']) {
            $status = 'critical';
        } elseif ($utilisation >= $settings['warning_threshold']) {
            $status = 'warning';
        } else {
            $status = 'ok';
        }

        return [
            'port' => $port,
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'total_bps' => $totalBps,
            'utilisation' => $utilisation,
            'status' => $status,
            'in_label' => Format::bits($inBps),
            'out_label' => Format::bits($outBps),
            'total_label' => Format::bits($totalBps),
            'peak_label' => Format::bits($peakBps),
            'speed_label' => $speedBps > 0 ? Format::bits($speedBps) : __('Unknown'),
            'utilisation_label' => Format::percent($utilisation),
            'group_names' => '',
        ];
    }

    private function summarise(array $stats): array
    {
        return [
            'matched' => $stats['matched'],
            'critical_count' => $stats['critical'],
            'warning_count' => $stats['warning'],
            'ok_count' => $stats['ok'],
            'unknown_count' => $stats['unknown'],
            'max_utilisation_label' => Format::percent($stats['util_max']),
            'avg_utilisation_label' => $stats['util_count'] > 0
                ? Format::percent($stats['util_sum'] / $stats['util_count'])
                : Format::percent(null),
            'total_in_label' => Format::bits($stats['in_bps']),
            'total_out_label' => Format::bits($stats['out_bps']),
            'total_traffic_label' => Format::bits($stats['total_bps']),
        ];
    }

    /**
     * Regex problems worth telling the user about, without blocking the render.
     */
    private function regexProblems(SafeRegex $include, SafeRegex $exclude, array $settings): array
    {
        $problems = [];

        $original = SafeRegex::make($settings['uplink_regex']);

        if ($original->isInvalid()) {
            $problems[] = [
                'label' => __('Uplink match regex'),
                'reason' => $original->error() . ' ' . __('The default pattern was used instead.'),
            ];
        } elseif ($include->isDegraded()) {
            $problems[] = ['label' => __('Uplink match regex'), 'reason' => (string) $include->error()];
        }

        if ($exclude->isInvalid() || $exclude->isDegraded()) {
            $problems[] = [
                'label' => __('Exclude regex'),
                'reason' => $exclude->error() . ' ' . __('No ports were excluded.'),
            ];
        }

        return $problems;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);

        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        return view('widgets.settings.uplink-utilization-overview', $settings);
    }
}
