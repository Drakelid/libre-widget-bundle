<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Sensor;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\Format;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Power and battery condition per device or per site.
 *
 * Core's health-sensors widget makes you hand-pick individual sensors and gives no
 * site-level view. On an estate with rectifiers and UPS units in unmanned huts, the
 * question during a regional power event is "which sites are on battery and how long
 * have they got" -- that needs aggregation, not a sensor picker.
 *
 * Severity for `state` sensors comes from LibreNMS's own state_generic_value
 * (0 ok, 1 warning, 2 critical, 3 unknown) rather than pattern-matching the
 * description, which varies by vendor.
 */
class SitePowerStatusController extends BundleWidgetController
{
    protected string $name = 'site-power-status';

    public const SHOW_MODES = ['problems', 'all'];
    public const GROUP_MODES = ['device', 'location'];

    /** Sensor classes that describe power delivery and battery reserve. */
    private const CLASSES = ['charge', 'runtime', 'voltage', 'current', 'power', 'state'];

    /** Classes that actually indicate a battery, as opposed to any powered device. */
    private const BATTERY_CLASSES = ['charge', 'runtime'];

    /*
     * Plausibility bounds.
     *
     * Devices do report nonsense here. Runtime in particular arrives as a large negative
     * number on a lot of UPS hardware -- an unsigned SNMP counter read as signed -- which
     * would otherwise render as "-8715378 min" and, being below any sane threshold, paint
     * every card critical. A reading outside these bounds is treated as absent rather
     * than displayed or used to decide severity.
     */
    private const MAX_RUNTIME_MINUTES = 43200;   // 30 days; longer is not a battery
    private const MAX_VOLTAGE = 1000.0;
    private const MAX_POWER_WATTS = 1000000.0;

    private const CHUNK_SIZE = 1000;

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        'show' => 'problems',
        'min_runtime_minutes' => 30,
        'min_charge_percent' => 50,
        'voltage_low' => null,
        'voltage_high' => null,
        'group_by' => 'device',
        'limit' => 25,
        // Without this, every device reporting a PSU voltage counts as a "site" --
        // which on a real network is most of the estate, not the UPS fleet.
        'battery_only' => '1',

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
        $settings['group_by'] = Cast::choice($settings['group_by'] ?? 'device', self::GROUP_MODES, 'device');
        $settings['min_runtime_minutes'] = Cast::clampedInt($settings['min_runtime_minutes'] ?? 30, 0, 10080, 30);
        $settings['min_charge_percent'] = Cast::clampedFloat($settings['min_charge_percent'] ?? 50, 0, 100, 50);
        $settings['limit'] = Cast::clampedInt($settings['limit'] ?? 25, 1, 200, 25);
        $settings['battery_only'] = Cast::bool($settings['battery_only'] ?? true, true);

        $settings['voltage_low'] = is_numeric($settings['voltage_low'] ?? null)
            ? (float) $settings['voltage_low'] : null;
        $settings['voltage_high'] = is_numeric($settings['voltage_high'] ?? null)
            ? (float) $settings['voltage_high'] : null;

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

        $query = Sensor::hasAccess($user)
            ->where('sensors.sensor_deleted', 0)
            ->whereIn('sensors.sensor_class', self::CLASSES)
            ->whereNotNull('sensors.sensor_current')
            ->with(['device', 'translations'])
            ->select('sensors.*');

        DeviceGroups::scopeToDevices($query, $groupIds, 'sensors.device_id');

        /** @var array<int|string, array<string, mixed>> keyed by device id or location */
        $sites = [];

        $query->chunkById(self::CHUNK_SIZE, function ($sensors) use (&$sites, $settings): void {
            foreach ($sensors as $sensor) {
                $site = $this->siteKey($sensor, $settings['group_by']);

                if ($site === null) {
                    continue;
                }

                [$key, $label] = $site;

                $sites[$key] ??= [
                    'key' => $key,
                    'label' => $label,
                    'device' => $sensor->device,
                    'status' => 'ok',
                    'runtime_minutes' => null,
                    'charge_percent' => null,
                    'voltage' => null,
                    'load_watts' => null,
                    'states' => [],
                    'device_count' => 0,
                    'devices' => [],
                    'has_battery' => false,
                    'suspect' => 0,
                ];

                if (in_array($sensor->sensor_class, self::BATTERY_CLASSES, true)) {
                    $sites[$key]['has_battery'] = true;
                }

                $deviceId = (int) $sensor->device_id;

                if (! isset($sites[$key]['devices'][$deviceId])) {
                    $sites[$key]['devices'][$deviceId] = true;
                    $sites[$key]['device_count']++;
                }

                $this->applySensor($sites[$key], $sensor, $settings);
            }
        }, 'sensors.sensor_id', 'sensor_id');

        $rows = array_values($sites);

        if ($settings['battery_only']) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['has_battery']));
        }

        $suspectSites = count(array_filter($rows, fn (array $r): bool => $r['suspect'] > 0));

        foreach ($rows as $i => $row) {
            $rows[$i]['status'] = $this->classify($row, $settings);
            $rows[$i]['runtime_label'] = $row['runtime_minutes'] === null
                ? null
                : $this->formatMinutes($row['runtime_minutes']);
            $rows[$i]['charge_label'] = $row['charge_percent'] === null
                ? null
                : Format::percent($row['charge_percent']);
        }

        if ($settings['show'] === 'problems') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['status'] !== 'ok'));
        }

        $rows = $this->rank($rows, $settings['limit']);

        return view('widgets.site-power-status', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'site_count' => count($sites),
            'battery_sites' => count(array_filter($sites, fn (array $r): bool => $r['has_battery'])),
            'suspect_sites' => $suspectSites,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
        ]);
    }

    /**
     * @return array{0: int|string, 1: string}|null
     */
    private function siteKey(Sensor $sensor, string $groupBy): ?array
    {
        $device = $sensor->device;

        if ($device === null) {
            return null;
        }

        if ($groupBy === 'location') {
            $location = $device->location?->location;

            // Devices with no location would otherwise collapse into one fake site.
            return $location
                ? ['loc:' . $location, $location]
                : ['dev:' . $device->device_id, $device->displayName()];
        }

        return ['dev:' . $device->device_id, $device->displayName()];
    }

    /**
     * Fold one sensor reading into the site summary, keeping the worst value seen.
     */
    private function applySensor(array &$site, Sensor $sensor, array $settings): void
    {
        $value = (float) $sensor->sensor_current;

        switch ($sensor->sensor_class) {
            case 'runtime':
                // LibreNMS stores runtime in minutes (Sensor::formatValue multiplies by
                // 60 to display it). Anything negative or absurdly long is a bad read.
                if ($value < 0 || $value > self::MAX_RUNTIME_MINUTES) {
                    $site['suspect']++;
                    break;
                }

                // Keep the lowest across the site: the first battery to die is the one
                // that matters.
                $site['runtime_minutes'] = $site['runtime_minutes'] === null
                    ? $value
                    : min($site['runtime_minutes'], $value);
                break;

            case 'charge':
                if ($value < 0 || $value > 100) {
                    $site['suspect']++;
                    break;
                }

                $site['charge_percent'] = $site['charge_percent'] === null
                    ? $value
                    : min($site['charge_percent'], $value);
                break;

            case 'voltage':
                if ($value <= 0 || $value > self::MAX_VOLTAGE) {
                    $site['suspect']++;
                    break;
                }

                $site['voltage'] = $site['voltage'] === null
                    ? $value
                    : min($site['voltage'], $value);
                break;

            case 'power':
                if ($value < 0 || $value > self::MAX_POWER_WATTS) {
                    $site['suspect']++;
                    break;
                }

                $site['load_watts'] = max((float) ($site['load_watts'] ?? 0), $value);
                break;

            case 'state':
                $translation = $sensor->currentTranslation();

                if ($translation === null) {
                    break;
                }

                $generic = (int) $translation->state_generic_value;

                // 0 ok, 1 warning, 2 critical, 3 unknown -- LibreNMS convention.
                if ($generic > 0 && $generic < 3) {
                    $site['states'][] = [
                        'descr' => $sensor->sensor_descr,
                        'text' => $translation->state_descr,
                        'generic' => $generic,
                    ];
                }
                break;
        }
    }

    private function classify(array $site, array $settings): string
    {
        $status = 'ok';

        foreach ($site['states'] as $state) {
            if ($state['generic'] >= 2) {
                return 'critical';
            }

            $status = 'warning';
        }

        if ($site['runtime_minutes'] !== null
            && $settings['min_runtime_minutes'] > 0
            && $site['runtime_minutes'] < $settings['min_runtime_minutes']) {
            return 'critical';
        }

        if ($site['charge_percent'] !== null
            && $site['charge_percent'] < $settings['min_charge_percent']) {
            $status = 'warning';
        }

        $voltage = $site['voltage'];

        if ($voltage !== null) {
            if ($settings['voltage_low'] !== null && $voltage < $settings['voltage_low']) {
                return 'critical';
            }

            if ($settings['voltage_high'] !== null && $voltage > $settings['voltage_high']) {
                return 'critical';
            }
        }

        return $status;
    }

    /** Worst first, then the site with least runtime remaining. */
    private function rank(array $rows, int $limit): array
    {
        $weight = ['critical' => 0, 'warning' => 1, 'ok' => 2, 'unknown' => 3];

        usort($rows, function (array $a, array $b) use ($weight): int {
            $w = ($weight[$a['status']] ?? 9) <=> ($weight[$b['status']] ?? 9);

            if ($w !== 0) {
                return $w;
            }

            return ($a['runtime_minutes'] ?? PHP_FLOAT_MAX) <=> ($b['runtime_minutes'] ?? PHP_FLOAT_MAX);
        });

        return array_slice($rows, 0, $limit);
    }

    private function formatMinutes(float $minutes): string
    {
        if ($minutes < 60) {
            return __(':count min', ['count' => (int) round($minutes)]);
        }

        $hours = floor($minutes / 60);
        $rest = (int) round($minutes - ($hours * 60));

        return $rest > 0
            ? __(':h h :m min', ['h' => (int) $hours, 'm' => $rest])
            : __(':h h', ['h' => (int) $hours]);
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);

        return view('widgets.settings.site-power-status', $settings);
    }
}
