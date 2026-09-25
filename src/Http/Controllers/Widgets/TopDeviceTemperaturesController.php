<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Sensor;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\Format;
use Drakelid\NmsDashWidgets\Support\SafeRegex;
use Drakelid\NmsDashWidgets\Support\Temperature;
use Drakelid\NmsDashWidgets\Support\SensorInsights;
use Drakelid\NmsDashWidgets\Support\History;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hottest devices by temperature sensor. Commonly used for UPS / rectifier monitoring.
 *
 * Note this is one row PER DEVICE, showing that device's hottest sensor -- hence
 * `device_count` rather than a sensor count.
 */
class TopDeviceTemperaturesController extends BundleWidgetController
{
    protected string $name = 'top-device-temperatures';

    /** sensors.group value LibreNMS uses to tag transceiver sensors. */
    private const TRANSCEIVER_GROUP = 'transceiver';

    /** Rows pulled per database round trip. */
    private const CHUNK_SIZE = 1000;

    protected $defaults = [
        'title' => null,
        'device_count' => 10,
        'time_interval' => 60,
        // Legacy single-group setting, still merged for older saved widgets.
        'device_group' => null,
        'device_groups' => [],
        'only_up' => true,
        'include_module_sensors' => false,
        'warn_temp' => 70,
        'limit_temp' => 90,
        'ranking' => 'margin',
        'show_history' => true,
        'sensor_include_regex' => '',
        'sensor_exclude_regex' => '',

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
        $settings['ranking'] = Cast::choice($settings['ranking'] ?? 'margin', ['margin', 'temperature'], 'margin');
        $settings['show_history'] = Cast::bool($settings['show_history'] ?? true, true);
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['device_count'] = Cast::clampedInt($settings['device_count'] ?? 10, 1, 100, 10);
        // 0 disables the last-polled filter entirely.
        $settings['time_interval'] = Cast::clampedInt($settings['time_interval'] ?? 60, 0, 10080, 60);
        $settings['only_up'] = Cast::bool($settings['only_up'] ?? true, true);
        $settings['include_module_sensors'] = Cast::bool($settings['include_module_sensors'] ?? false, false);

        $warn = Cast::float($settings['warn_temp'] ?? 70, 70.0);
        $limit = Cast::float($settings['limit_temp'] ?? 90, 90.0);

        if ($warn >= $limit) {
            $warn = $limit - 1;
        }

        $settings['warn_temp'] = $warn;
        $settings['limit_temp'] = $limit;

        $settings['sensor_include_regex'] = trim((string) ($settings['sensor_include_regex'] ?? ''));
        $settings['sensor_exclude_regex'] = trim((string) ($settings['sensor_exclude_regex'] ?? ''));

        // Merge the modern multi-select with the legacy scalar so widgets saved before
        // multi-group support keep their filter.
        $settings['device_groups'] = array_values(array_unique(array_merge(
            DeviceGroups::ids($settings['device_groups'] ?? []),
            DeviceGroups::ids($settings['device_group'] ?? null),
        )));

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

        $include = SafeRegex::make($settings['sensor_include_regex']);
        $exclude = SafeRegex::make($settings['sensor_exclude_regex']);

        $query = Sensor::hasAccess($user)
            ->where('sensors.sensor_deleted', 0)
            ->where('sensors.sensor_class', 'temperature')
            ->whereNotNull('sensors.sensor_current')
            ->with('device')
            ->select('sensors.*')
            ->when(! $settings['include_module_sensors'], function ($query): void {
                /*
                 * Structural filter for transceiver sensors.
                 *
                 * LibreNMS tags them with sensors.group = 'transceiver' (see core's
                 * TransceiverSensors view component). Filtering here in SQL is both
                 * faster and more accurate than the original's text heuristic, which
                 * excluded anything containing the word "port" and so caught some
                 * legitimate chassis sensors.
                 *
                 * The heuristic is still applied afterwards in PHP for devices that do
                 * not populate the group column.
                 */
                $query->where(function ($q): void {
                    $q->whereNull('sensors.group')
                        ->orWhere('sensors.group', '!=', self::TRANSCEIVER_GROUP);
                });
            })
            ->whereHas('device', function ($query) use ($settings): void {
                if ($settings['only_up']) {
                    $query->where('status', 1)->where('disabled', 0)->where('ignore', 0);
                }

                if ($settings['time_interval'] > 0) {
                    $query->where('last_polled', '>', Carbon::now()->subMinutes($settings['time_interval']));
                }
            });

        DeviceGroups::scopeToDevices($query, $groupIds, 'sensors.device_id');

        $excludedModuleCount = 0;
        $excludedRegexCount = 0;
        $candidateCount = 0;
        $hottestPerDevice = [];
        $deviceSensors = [];

        // Stream models in chunks; retain one ranked model per device and compact
        // sensor summaries for the expandable device details.
        $query->chunkById(self::CHUNK_SIZE, function ($sensors) use (
            &$excludedModuleCount, &$excludedRegexCount, &$candidateCount, &$hottestPerDevice, &$deviceSensors,
            $settings, $include, $exclude
        ): void {
            foreach ($sensors as $sensor) {
                $candidateCount++;
                if (! $settings['include_module_sensors'] && $this->looksLikeInterfaceModuleTemperature($sensor)) {
                    $excludedModuleCount++;
                    continue;
                }

                if (! $this->matchesRegexFilters($sensor, $include, $exclude)) {
                    $excludedRegexCount++;
                    continue;
                }

                $scale = Temperature::sensorScaleFactor($sensor);
                $current = Temperature::value($sensor->sensor_current, $scale);

                if ($current === null) {
                    continue;
                }

                $deviceId = (int) $sensor->device_id;

                $limits = SensorInsights::temperatureLimits($sensor, $settings['warn_temp'], $settings['limit_temp']);
                $candidate = [
                    'sensor' => $sensor,
                    'current' => $current,
                    'margin' => $limits['limit'] - $current,
                    'limits' => $limits,
                    'status' => Temperature::status($current, $limits['warn'], $limits['limit']),
                    'current_text' => Format::temperature($current),
                    'observed_at' => SensorInsights::polledAt($sensor),
                ];
                // Retain compact details, not every model, for expandable device context.
                $deviceSensors[$deviceId][] = [
                    'id' => $sensor->sensor_id, 'descr' => $sensor->sensor_descr,
                    'current' => $current, 'margin' => $candidate['margin'],
                    'status' => $candidate['status'], 'observed_at' => SensorInsights::polledAt($sensor),
                ];
                $previous = $hottestPerDevice[$deviceId] ?? null;
                if ($previous === null || ($settings['ranking'] === 'margin'
                    ? $candidate['margin'] < $previous['margin']
                    : $current > $previous['current'])) {
                    $hottestPerDevice[$deviceId] = $candidate;
                }
            }
        }, 'sensors.sensor_id', 'sensor_id');

        $rows = collect($hottestPerDevice)
            ->sortBy(fn (array $row): float => $settings['ranking'] === 'margin' ? $row['margin'] : -$row['current'])
            ->take($settings['device_count'])
            ->values();

        $maxShown = max(1.0, (float) ($rows->max('current') ?? 0));

        $rows = $rows->map(function (array $row) use ($settings, $maxShown, $deviceSensors): array {
            $row['percent'] = Temperature::barPercent($row['current'], $row['limits']['limit'], $maxShown);
            $row['caption'] = __('Limit: :limit · Warn: :warn', [
                'limit' => Format::temperature($row['limits']['limit']),
                'warn' => Format::temperature($row['limits']['warn']),
            ]);

            $row['other_sensors'] = $deviceSensors[(int) $row['sensor']->device_id] ?? [];
            $row['trend'] = $settings['show_history'] ? History::sensorTrend($row['sensor']) : ['available' => false, 'reason' => __('History disabled')];
            $row['trend'] = SensorInsights::temperatureTrend($row['trend'], Temperature::sensorScaleFactor($row['sensor']));

            return $row;
        });

        return view('widgets.top-device-temperatures', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'matched_count' => count($hottestPerDevice),
            'candidate_count' => $candidateCount,
            'excluded_module_count' => $excludedModuleCount,
            'excluded_regex_count' => $excludedRegexCount,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All device groups')),
            'regex_problems' => $this->regexProblems($include, $exclude),
            'include_matches_everything' => $include->matchesEmptyString(),
        ]);
    }

    /**
     * Text the regex filters are matched against, lowercased.
     */
    private function regexTargetText(Sensor $sensor): string
    {
        $device = $sensor->device;

        $parts = [
            $device?->hostname,
            $device ? $device->displayName() : null,
            $sensor->sensor_descr,
            $sensor->sensor_type,
            $sensor->sensor_index,
        ];

        return strtolower(trim(implode(' ', array_filter(array_map(
            fn ($value) => is_scalar($value) ? (string) $value : '',
            $parts
        )))));
    }

    private function matchesRegexFilters(Sensor $sensor, SafeRegex $include, SafeRegex $exclude): bool
    {
        $target = $this->regexTargetText($sensor);

        if ($include->isUsable() && ! $include->matches($target)) {
            return false;
        }

        if ($exclude->isUsable() && $exclude->matches($target)) {
            return false;
        }

        return true;
    }

    /**
     * Heuristic fallback for optics and interface module sensors.
     *
     * PORTED FROM THE ORIGINAL. Kept as a second line of defence behind the structural
     * sensors.group filter, for devices that do not populate that column.
     */
    private function looksLikeInterfaceModuleTemperature(Sensor $sensor): bool
    {
        $text = strtolower(trim(implode(' ', array_filter([
            $sensor->sensor_descr ?? '',
            $sensor->sensor_type ?? '',
            (string) ($sensor->sensor_index ?? ''),
        ]))));

        if ($text === '') {
            return false;
        }

        $hardExcludes = [
            '/\btransceiver\b/i',
            '/\boptic\b/i',
            '/\boptical\b/i',
            '/\bsfp\b/i',
            '/\bqsfp\b/i',
            '/\bxfp\b/i',
            '/\bgbic\b/i',
            '/\bdom\b/i',
            '/\blaser\b/i',
            '/\bport\b/i',
        ];

        foreach ($hardExcludes as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Interface names with slot/port numbering: Gi0/0/1, Te1/9, hundredgig...
        if (preg_match('/\b(gi|te|fa|eth|ethernet|tengig|twentyfivegig|fortygig|hundredgig|hu|fo)\s*\d+(\/\d+)+\b/i', $text)) {
            return true;
        }

        // Juniper style: ge-0/0/0, xe-1/2/3
        if (preg_match('/\b(ge|xe|et|ae)-\d+\/\d+\/\d+\b/i', $text)) {
            return true;
        }

        if (str_contains($text, 'module') && preg_match('/\br\d+\s*-\s*.*\br\d+\/\d+\b/i', $text)) {
            return true;
        }

        if (
            str_contains($text, 'module')
            && preg_match('/\d+\/\d+/', $text)
            && ! preg_match('/\bchassis\b|\bsystem\b|\bboard\b|\bcpu\b|\bambient\b/i', $text)
        ) {
            return true;
        }

        return false;
    }

    private function regexProblems(SafeRegex $include, SafeRegex $exclude): array
    {
        $problems = [];

        if ($include->isInvalid() || $include->isDegraded()) {
            $problems[] = [
                'label' => __('Include sensor regex'),
                'reason' => $include->error() . ' ' . __('The filter was ignored.'),
            ];
        }

        if ($exclude->isInvalid() || $exclude->isDegraded()) {
            $problems[] = [
                'label' => __('Exclude sensor regex'),
                'reason' => $exclude->error() . ' ' . __('The filter was ignored.'),
            ];
        }

        return $problems;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);

        $groupIds = array_values(array_unique(array_merge(
            DeviceGroups::ids($settings['device_groups'] ?? []),
            DeviceGroups::ids($settings['device_group'] ?? null),
        )));

        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);
        $settings['only_up'] = Cast::bool($settings['only_up'] ?? true, true);
        $settings['include_module_sensors'] = Cast::bool($settings['include_module_sensors'] ?? false, false);

        $settings['layouts'] = Presentation::layoutsFor($this->name);

        return view('widgets.settings.top-device-temperatures', $settings);
    }
}
