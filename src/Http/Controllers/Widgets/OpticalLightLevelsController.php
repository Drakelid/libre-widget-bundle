<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Port;
use App\Models\Sensor;
use App\Models\Transceiver;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\SafeRegex;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Optical receive/transmit levels, ranked by how close each is to its low threshold.
 *
 * LibreNMS polls digital diagnostics (sensor_class = 'dbm') but ships nothing that
 * displays them, and this bundle's temperature widget deliberately excludes transceiver
 * sensors -- so on a fibre network the data was being collected and discarded.
 *
 * The value here is lead time. A dirty connector or an ageing optic drifts for days
 * before the link drops, so ranking by margin turns an out-of-hours callout into
 * scheduled maintenance.
 *
 * Note this widget cares about the LOW limits. The temperature widget only uses the
 * high side; for optics it is falling receive power that predicts failure.
 */
class OpticalLightLevelsController extends BundleWidgetController
{
    protected string $name = 'optical-light-levels';

    public const MODES = ['worst_margin', 'rx_only', 'tx_only', 'all'];

    private const CHUNK_SIZE = 1000;

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        'sensor_count' => 20,
        'mode' => 'worst_margin',
        'warn_margin_db' => 3,
        'include_regex' => '',
        'exclude_regex' => '',
        'show_transceiver_details' => true,
        'only_with_limits' => true,

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
        $settings['sensor_count'] = Cast::clampedInt($settings['sensor_count'] ?? 20, 1, 200, 20);
        $settings['mode'] = Cast::choice($settings['mode'] ?? 'worst_margin', self::MODES, 'worst_margin');
        $settings['warn_margin_db'] = Cast::clampedFloat($settings['warn_margin_db'] ?? 3, 0, 30, 3);
        $settings['include_regex'] = trim((string) ($settings['include_regex'] ?? ''));
        $settings['exclude_regex'] = trim((string) ($settings['exclude_regex'] ?? ''));
        $settings['show_transceiver_details'] = Cast::bool($settings['show_transceiver_details'] ?? true, true);
        $settings['only_with_limits'] = Cast::bool($settings['only_with_limits'] ?? true, true);

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
        $include = SafeRegex::make($settings['include_regex']);
        $exclude = SafeRegex::make($settings['exclude_regex']);

        $query = Sensor::hasAccess($user)
            ->where('sensors.sensor_deleted', 0)
            ->where('sensors.sensor_class', 'dbm')
            ->whereNotNull('sensors.sensor_current')
            ->with('device')
            ->select('sensors.*');

        DeviceGroups::scopeToDevices($query, $groupIds, 'sensors.device_id');

        $rows = [];
        $skippedNoLimit = 0;
        $keep = $settings['sensor_count'];
        $highWater = max($keep * 4, 200);

        $query->chunkById(self::CHUNK_SIZE, function ($sensors) use (
            &$rows, &$skippedNoLimit, $settings, $include, $exclude, $keep, $highWater
        ): void {
            foreach ($sensors as $sensor) {
                $direction = $this->direction($sensor);

                if ($settings['mode'] === 'rx_only' && $direction !== 'rx') {
                    continue;
                }

                if ($settings['mode'] === 'tx_only' && $direction !== 'tx') {
                    continue;
                }

                $haystack = $this->haystack($sensor);

                if ($include->isUsable() && ! $include->matches($haystack)) {
                    continue;
                }

                if ($exclude->isUsable() && $exclude->matches($haystack)) {
                    continue;
                }

                $low = is_numeric($sensor->sensor_limit_low) ? (float) $sensor->sensor_limit_low : null;
                $high = is_numeric($sensor->sensor_limit) ? (float) $sensor->sensor_limit : null;

                if ($low === null && $settings['only_with_limits']) {
                    $skippedNoLimit++;
                    continue;
                }

                $current = (float) $sensor->sensor_current;
                // Margin is headroom above the low threshold: smaller means closer to dark.
                $margin = $low === null ? null : $current - $low;

                $rows[] = [
                    'sensor' => $sensor,
                    'direction' => $direction,
                    'current' => $current,
                    'low' => $low,
                    'high' => $high,
                    'margin' => $margin,
                    'status' => $this->status($current, $low, $high, $settings['warn_margin_db']),
                ];

                if (count($rows) >= $highWater) {
                    $rows = $this->trim($rows, $keep);
                }
            }
        }, 'sensors.sensor_id', 'sensor_id');

        $rows = $this->trim($rows, $keep);
        $rows = $this->attachPorts($rows, $settings['show_transceiver_details']);

        return view('widgets.optical-light-levels', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
            'skipped_no_limit' => $skippedNoLimit,
            'regex_problems' => $this->regexProblems($include, $exclude),
        ]);
    }

    /**
     * Order by margin ascending: the link closest to its low threshold comes first.
     * Readings with no low limit have no margin and always sort last.
     */
    private function trim(array $rows, int $keep): array
    {
        usort($rows, function (array $a, array $b): int {
            $aM = $a['margin'] ?? PHP_FLOAT_MAX;
            $bM = $b['margin'] ?? PHP_FLOAT_MAX;

            if ($aM === $bM) {
                return $a['current'] <=> $b['current'];
            }

            return $aM <=> $bM;
        });

        return array_slice($rows, 0, $keep);
    }

    /**
     * Attach the transceiver and port each reading belongs to.
     *
     * LibreNMS links a sensor to a transceiver by device_id + entPhysicalIndex; core
     * does the same in app/View/Components/TransceiverSensors.php. Both lookups are
     * done once for the displayed rows rather than per row.
     */
    private function attachPorts(array $rows, bool $withDetails): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $deviceIds = array_values(array_unique(array_map(
            fn (array $r): int => (int) $r['sensor']->device_id,
            $rows
        )));

        $transceivers = Transceiver::query()
            ->whereIntegerInRaw('device_id', $deviceIds)
            ->get()
            ->keyBy(fn (Transceiver $t): string => $t->device_id . ':' . $t->entity_physical_index);

        $ports = Port::query()
            ->whereIntegerInRaw('port_id', $transceivers->pluck('port_id')->filter()->unique()->values()->all() ?: [0])
            ->get()
            ->keyBy('port_id');

        foreach ($rows as $i => $row) {
            $key = $row['sensor']->device_id . ':' . $row['sensor']->entPhysicalIndex;
            $transceiver = $transceivers->get($key);

            $rows[$i]['transceiver'] = $withDetails ? $transceiver : null;
            $rows[$i]['port'] = $transceiver && $transceiver->port_id
                ? $ports->get($transceiver->port_id)
                : null;
        }

        return $rows;
    }

    /**
     * RX or TX, read from the sensor description. Vendors are inconsistent, so anything
     * unrecognised stays null rather than being guessed at.
     */
    private function direction(Sensor $sensor): ?string
    {
        $text = strtolower(trim(($sensor->sensor_descr ?? '') . ' ' . ($sensor->sensor_type ?? '')));

        if (preg_match('/\b(rx|receive|input|in)\b/', $text)) {
            return 'rx';
        }

        if (preg_match('/\b(tx|transmit|output|out)\b/', $text)) {
            return 'tx';
        }

        return null;
    }

    private function haystack(Sensor $sensor): string
    {
        $device = $sensor->device;

        return strtolower(trim(implode(' ', array_filter([
            $device?->hostname,
            $device ? $device->displayName() : null,
            $sensor->sensor_descr,
            $sensor->sensor_type,
            (string) ($sensor->sensor_index ?? ''),
        ]))));
    }

    /**
     * Critical at or beyond either limit, warning within warn_margin_db of the low
     * limit. Overdrive (above the high limit) matters too: it cooks the far-end optic.
     */
    private function status(float $current, ?float $low, ?float $high, float $warnMargin): string
    {
        if ($low !== null && $current <= $low) {
            return 'critical';
        }

        if ($high !== null && $current >= $high) {
            return 'critical';
        }

        if ($low !== null && $current <= ($low + $warnMargin)) {
            return 'warning';
        }

        if ($low === null && $high === null) {
            return 'unknown';
        }

        return 'ok';
    }

    private function regexProblems(SafeRegex $include, SafeRegex $exclude): array
    {
        $problems = [];

        foreach ([[__('Include regex'), $include], [__('Exclude regex'), $exclude]] as [$label, $regex]) {
            if ($regex->isInvalid() || $regex->isDegraded()) {
                $problems[] = ['label' => $label, 'reason' => $regex->error() . ' ' . __('The filter was ignored.')];
            }
        }

        return $problems;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);

        return view('widgets.settings.optical-light-levels', $settings);
    }
}
