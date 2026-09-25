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
use Drakelid\NmsDashWidgets\Support\Optical;
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

        // Thresholds set in the widget, in dBm, per direction. null means "not set":
        // the optic's own value is used, or none if it reports none. LibreNMS never
        // guesses limits for dBm sensors, so for optics without DDM thresholds these
        // are the only limits there are.
        'rx_low' => null,
        'rx_low_warn' => null,
        'rx_high_warn' => null,
        'rx_high' => null,
        'tx_low' => null,
        'tx_low_warn' => null,
        'tx_high_warn' => null,
        'tx_high' => null,
        'threshold_priority' => Optical::PRIORITY_OPTIC,

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

        foreach (['rx', 'tx'] as $dir) {
            foreach (Optical::LIMITS as $limit) {
                $key = $dir . '_' . $limit;
                $settings[$key] = Cast::nullableFloat($settings[$key] ?? null, Optical::MIN_DBM, Optical::MAX_DBM);
            }
        }

        $settings['threshold_priority'] = Cast::choice(
            $settings['threshold_priority'] ?? null,
            Optical::PRIORITIES,
            Optical::PRIORITY_OPTIC
        );

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
        $custom = $this->customThresholds($settings);

        $query = Sensor::hasAccess($user)
            ->where('sensors.sensor_deleted', 0)
            ->where('sensors.sensor_class', 'dbm')
            ->whereNotNull('sensors.sensor_current')
            ->whereHas('device', function ($query): void {
                $query->where('status', 1)->where('disabled', 0);
            })
            // Filter before ranking/limiting: stale readings from inactive links
            // must not crowd active links out of the widget.
            ->whereExists(function ($ports): void {
                $ports->selectRaw('1')
                    ->from('ports')
                    ->whereColumn('ports.device_id', 'sensors.device_id')
                    ->where('ports.deleted', 0)
                    ->where('ports.disabled', 0)
                    ->where('ports.ifAdminStatus', 'up')
                    ->where('ports.ifOperStatus', 'up')
                    ->where('sensors.entPhysicalIndex', '<>', '')
                    ->where(function ($mapping): void {
                        // Some discovery modules store an ifIndex directly.
                        $mapping->where(function ($direct): void {
                            $direct->whereIn('sensors.entPhysicalIndex_measured', ['port', 'ports'])
                                ->whereColumn('ports.ifIndex', 'sensors.entPhysicalIndex');
                        })->orWhere(function ($entity): void {
                            // Otherwise use the same entity mapping as attachPorts().
                            // Do not interpret a known ifIndex as an entity index.
                            $entity->where(function ($type): void {
                                $type->whereNull('sensors.entPhysicalIndex_measured')
                                    ->orWhereNotIn('sensors.entPhysicalIndex_measured', ['port', 'ports']);
                            })->whereExists(function ($transceivers): void {
                                $transceivers->selectRaw('1')
                                    ->from('transceivers')
                                    ->whereColumn('transceivers.device_id', 'sensors.device_id')
                                    ->whereColumn('transceivers.port_id', 'ports.port_id')
                                    ->whereNotNull('transceivers.entity_physical_index')
                                    ->whereColumn('transceivers.entity_physical_index', 'sensors.entPhysicalIndex');
                            });
                        });
                    });
            })
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
            $settings, $include, $exclude, $keep, $highWater, $custom
        ): void {
            foreach ($sensors as $sensor) {
                $totalSeen++;
                $direction = Optical::direction(
                    (string) ($sensor->sensor_descr ?? ''),
                    (string) ($sensor->sensor_type ?? '')
                );

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

                // The optic's own limits merged with any set in the widget. Readings whose
                // direction is unknown take the receive values: falling receive power is
                // what this widget exists to catch.
                [$limits, $fromCustom] = Optical::mergeLimits(
                    [
                        'low' => $this->limit($sensor->sensor_limit_low),
                        'low_warn' => $this->limit($sensor->sensor_limit_low_warn),
                        'high_warn' => $this->limit($sensor->sensor_limit_warn),
                        'high' => $this->limit($sensor->sensor_limit),
                    ],
                    $custom[$direction === 'tx' ? 'tx' : 'rx'],
                    $settings['threshold_priority']
                );
                $low = $limits['low'];

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
                    'high' => $limits['high'],
                    'custom' => $fromCustom,
                    'margin' => $margin,
                    'status' => Optical::status($current, $limits, $settings['warn_margin_db']),
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
     * off the low-threshold filter, shows optics that have no limits at all, which sort
     * last under a margin ranking. Setting a low threshold in the widget is the other
     * way to bring those into view, and the one that ranks them.
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
     * Use the same mapping as the active-interface filter: explicit port indexes
     * refer to ifIndex, while other indexes refer to transceiver entities.
     * Lookups are batched for the displayed devices rather than per sensor.
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
            ->get();
        $byEntity = $transceivers->filter(fn (Transceiver $t): bool => $t->entity_physical_index !== null && $t->entity_physical_index !== '')
            ->keyBy(fn (Transceiver $t): string => $t->device_id . ':' . $t->entity_physical_index);
        $byPort = $transceivers->filter(fn (Transceiver $t): bool => ! empty($t->port_id))
            ->keyBy(fn (Transceiver $t): string => $t->device_id . ':' . $t->port_id);

        $ports = Port::query()
            ->whereIntegerInRaw('device_id', $deviceIds)
            ->where('deleted', 0)
            ->where('disabled', 0)
            ->where('ifAdminStatus', 'up')
            ->where('ifOperStatus', 'up')
            ->get()
            ->keyBy('port_id');
        $byIfIndex = $ports->keyBy(fn (Port $p): string => $p->device_id . ':' . $p->ifIndex);

        foreach ($rows as $i => $row) {
            $sensor = $row['sensor'];
            $index = $sensor->entPhysicalIndex;
            $port = null;
            $transceiver = null;
            if ($index !== null && $index !== '') {
                if (in_array($sensor->entPhysicalIndex_measured, ['port', 'ports'], true)) {
                    $port = $byIfIndex->get($sensor->device_id . ':' . $index);
                    $transceiver = $port ? $byPort->get($sensor->device_id . ':' . $port->port_id) : null;
                } else {
                    $transceiver = $byEntity->get($sensor->device_id . ':' . $index);
                    $port = $transceiver ? $ports->get($transceiver->port_id) : null;
                    if ($port && (int) $port->device_id !== (int) $sensor->device_id) {
                        $port = null;
                    }
                }
            }

            $rows[$i]['transceiver'] = $withDetails ? $transceiver : null;
            $rows[$i]['port'] = $port;
        }

        return $rows;
    }

    /**
     * The thresholds set in the widget, grouped by direction.
     *
     * @return array{rx: array<string, ?float>, tx: array<string, ?float>}
     */
    private function customThresholds(array $settings): array
    {
        $thresholds = [];

        foreach (['rx', 'tx'] as $dir) {
            foreach (Optical::LIMITS as $limit) {
                $thresholds[$dir][$limit] = $settings[$dir . '_' . $limit] ?? null;
            }
        }

        return $thresholds;
    }

    /** A stored sensor limit, or null where the optic reports none. */
    private function limit(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
        $settings['custom_thresholds'] = $this->customThresholds($settings);

        return view('widgets.settings.optical-light-levels', $settings);
    }
}
