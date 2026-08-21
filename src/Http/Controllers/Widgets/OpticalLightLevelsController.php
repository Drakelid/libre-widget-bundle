<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Port;
use App\Models\Sensor;
use App\Models\Transceiver;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
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
        $settings['sensor_count'] = Cast::clampedInt($settings['sensor_count'] ?? 20, 1, 200, 20);
        $settings['mode'] = Cast::choice($settings['mode'] ?? 'worst_margin', self::MODES, 'worst_margin');
        $settings['warn_margin_db'] = Cast::clampedFloat($settings['warn_margin_db'] ?? 3, 0, 30, 3);
        $settings['include_regex'] = trim((string) ($settings['include_regex'] ?? ''));
        $settings['exclude_regex'] = trim((string) ($settings['exclude_regex'] ?? ''));
        $settings['show_transceiver_details'] = Cast::bool($settings['show_transceiver_details'] ?? true, true);
        $settings['only_with_limits'] = Cast::bool($settings['only_with_limits'] ?? true, true);

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
        $skippedDirection = 0;
        $skippedRegex = 0;
        $totalSeen = 0;
        $keep = $settings['sensor_count'];
        $highWater = max($keep * 4, 200);

        $query->chunkById(self::CHUNK_SIZE, function ($sensors) use (
            &$rows, &$skippedNoLimit, &$skippedDirection, &$skippedRegex, &$totalSeen,
            $settings, $include, $exclude, $keep, $highWater
        ): void {
            foreach ($sensors as $sensor) {
                $totalSeen++;
                $direction = $this->direction($sensor);

                if ($settings['mode'] === 'rx_only' && $direction !== 'rx') {
                    $skippedDirection++;
                    continue;
                }

                if ($settings['mode'] === 'tx_only' && $direction !== 'tx') {
                    $skippedDirection++;
                    continue;
                }

                $haystack = $this->haystack($sensor);

                if ($include->isUsable() && ! $include->matches($haystack)) {
                    $skippedRegex++;
                    continue;
                }

                if ($exclude->isUsable() && $exclude->matches($haystack)) {
                    $skippedRegex++;
                    continue;
                }

                $low = is_numeric($sensor->sensor_limit_low) ? (float) $sensor->sensor_limit_low : null;
                $high = is_numeric($sensor->sensor_limit) ? (float) $sensor->sensor_limit : null;

                // DDM optics report their own warn thresholds a little inside the alarm
                // thresholds, and discovery stores them. They describe the actual part
                // far better than one flat dB figure applied across every optic, so they
                // win where present; warn_margin_db covers the ones that report none.
                $lowWarn = is_numeric($sensor->sensor_limit_low_warn) ? (float) $sensor->sensor_limit_low_warn : null;
                $highWarn = is_numeric($sensor->sensor_limit_warn) ? (float) $sensor->sensor_limit_warn : null;

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
                    'status' => $this->status($current, $low, $high, $lowWarn, $highWarn, $settings['warn_margin_db']),
                ];

                if (count($rows) >= $highWater) {
                    $rows = $this->trim($rows, $keep, $settings['mode']);
                }
            }
        }, 'sensors.sensor_id', 'sensor_id');

        $rows = $this->trim($rows, $keep, $settings['mode']);
        // Driven by the visible column, not by the legacy show_transceiver_details flag:
        // Columns seeds itself from that flag, but once a user sets columns explicitly the
        // two can disagree, and then the Optic column renders empty cells.
        $rows = $this->attachPorts($rows, (bool) $settings['cols']['optic']);

        return view('widgets.optical-light-levels', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
            'skipped_no_limit' => $skippedNoLimit,
            'skipped_direction' => $skippedDirection,
            'skipped_regex' => $skippedRegex,
            'total_seen' => $totalSeen,
            'regex_problems' => $this->regexProblems($include, $exclude),
            'ordering' => $settings['mode'] === 'all'
                ? __('listed by device')
                : __('ranked by margin above the low threshold'),
        ]);
    }

    /**
     * Reduce to the rows worth showing, in the order the chosen mode implies.
     *
     * Every mode except `all` ranks by margin ascending, so the link closest to its low
     * threshold comes first; readings with no low limit have no margin and sort last.
     *
     * `all` is offered in the settings as "All optical readings" and used to rank by
     * margin like everything else, which made it a duplicate of "Worst margin" and meant
     * the option silently did nothing. It now lists by device and description instead,
     * which is what an inventory view of the optics wants -- and, combined with turning
     * off the low-threshold filter, is the only way to see optics that report no limits
     * at all, since those always sort last under a margin ranking.
     */
    private function trim(array $rows, int $keep, string $mode): array
    {
        if ($mode === 'all') {
            usort($rows, function (array $a, array $b): int {
                $aD = $a['sensor']->device?->displayName() ?? '';
                $bD = $b['sensor']->device?->displayName() ?? '';

                return [$aD, (string) $a['sensor']->sensor_descr]
                    <=> [$bD, (string) $b['sensor']->sensor_descr];
            });

            return array_slice($rows, 0, $keep);
        }

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

        // whereNotNull matters: entity_physical_index is nullable, and so is the sensor
        // side. Without it every unindexed sensor on a device keys to "<id>:" and so does
        // every unindexed transceiver, so the first such transceiver would be attached to
        // all of them -- showing the wrong port and the wrong optic. Core's
        // TransceiverSensors component guards the same way.
        $transceivers = Transceiver::query()
            ->whereIntegerInRaw('device_id', $deviceIds)
            ->whereNotNull('entity_physical_index')
            ->get()
            ->keyBy(fn (Transceiver $t): string => $t->device_id . ':' . $t->entity_physical_index);

        $ports = Port::query()
            ->whereIntegerInRaw('port_id', $transceivers->pluck('port_id')->filter()->unique()->values()->all() ?: [0])
            ->get()
            ->keyBy('port_id');

        foreach ($rows as $i => $row) {
            $index = $row['sensor']->entPhysicalIndex;
            $transceiver = ($index === null || $index === '')
                ? null
                : $transceivers->get($row['sensor']->device_id . ':' . $index);

            $rows[$i]['transceiver'] = $withDetails ? $transceiver : null;
            $rows[$i]['port'] = $transceiver && $transceiver->port_id
                ? $ports->get($transceiver->port_id)
                : null;
        }

        return $rows;
    }

    /**
     * RX or TX, read from the sensor description.
     *
     * Deliberately does NOT match a bare "in" or "out". Those appear in ordinary prose
     * ("power in dBm"), and because receive is tested first a transmit sensor whose
     * description happened to contain the word "in" was being labelled RX -- and then
     * excluded by the tx_only filter. The explicit "input"/"output" alternatives cover
     * the real vendor wording without that risk.
     *
     * Vendors are inconsistent, so anything unrecognised stays null rather than being
     * guessed at; those readings only appear in the combined modes.
     */
    private function direction(Sensor $sensor): ?string
    {
        $text = strtolower(trim(($sensor->sensor_descr ?? '') . ' ' . ($sensor->sensor_type ?? '')));

        if (preg_match('/\b(rx|recv|receive|received|input)\b/', $text)) {
            return 'rx';
        }

        if (preg_match('/\b(tx|xmit|transmit|transmitted|output)\b/', $text)) {
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
     * Critical at or beyond either alarm limit. Overdrive (above the high limit) matters
     * too: it cooks the far-end receiver.
     *
     * Warning prefers the thresholds the optic itself reports over the flat
     * warn_margin_db setting. A long-haul optic and a 10m DAC have very different ideas
     * of what "close to dark" means, and the module already knows which it is.
     */
    private function status(
        float $current,
        ?float $low,
        ?float $high,
        ?float $lowWarn,
        ?float $highWarn,
        float $warnMargin
    ): string {
        if ($low !== null && $current <= $low) {
            return 'critical';
        }

        if ($high !== null && $current >= $high) {
            return 'critical';
        }

        if ($lowWarn !== null && $current <= $lowWarn) {
            return 'warning';
        }

        if ($highWarn !== null && $current >= $highWarn) {
            return 'warning';
        }

        // Only when the optic reports no low warn threshold of its own.
        if ($lowWarn === null && $low !== null && $current <= ($low + $warnMargin)) {
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
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.optical-light-levels', $settings);
    }
}
