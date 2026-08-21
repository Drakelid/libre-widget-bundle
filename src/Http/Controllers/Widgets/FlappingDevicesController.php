<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Device;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Devices and ports that changed state repeatedly within a lookback window.
 *
 * Data comes from the eventlog. This widget is inherently MySQL/MariaDB specific
 * because it relies on REGEXP for message matching; LibreNMS requires MariaDB, so
 * that is acceptable, but it is worth knowing.
 *
 * Note `device_group` here is a SCALAR and uses core's reserved settings key, which
 * makes core's WidgetController resolve it to a model and append the group name to
 * the widget title. That is existing behaviour -- do not "modernise" it to the plural
 * device_groups used by the other widgets, or saved placements lose their filter.
 */
class FlappingDevicesController extends BundleWidgetController
{
    protected string $name = 'flapping-devices';

    public const SHOW_TYPES = ['all', 'devices', 'ports'];

    protected $defaults = [
        'title' => null,
        'lookback_hours' => 24,
        'min_changes' => 3,
        'limit' => 15,
        'show_type' => 'all',
        'device_group' => null,
        'refresh' => 60,

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
        $settings['lookback_hours'] = Cast::clampedInt($settings['lookback_hours'] ?? 24, 1, 168, 24);
        $settings['min_changes'] = Cast::clampedInt($settings['min_changes'] ?? 3, 2, 100, 3);
        $settings['limit'] = Cast::clampedInt($settings['limit'] ?? 15, 1, 100, 15);
        $settings['show_type'] = Cast::choice($settings['show_type'] ?? 'all', self::SHOW_TYPES, 'all');

        $settings = Columns::normalize($settings, $this->name);
        $settings = Presentation::normalize($settings, $this->name);

        return $settings;
    }

    public function getView(Request $request): View|string
    {
        $settings = $this->settings();

        // `auto` becomes a concrete layout here, using the widget body size the
        // dashboard posts with every refresh.
        $settings['layout'] = Presentation::resolveLayout($settings, $this->name, $request);
        $settings['widget_classes'] = Presentation::cssClasses($settings, $this->name, $settings['layout']);
        $settings['cols'] = Columns::visible($settings, $this->name);
        $since = Carbon::now()->subHours($settings['lookback_hours'])->toDateTimeString();

        // Resolve accessible devices first; the eventlog itself carries no permissions.
        $deviceQuery = Device::hasAccess($request->user())->select('devices.device_id');

        // device_group is a scalar here, so core's single-group scope is the right tool.
        if (! empty($settings['device_group'])) {
            $groupId = $settings['device_group'] instanceof \App\Models\DeviceGroup
                ? $settings['device_group']->id
                : $settings['device_group'];

            $deviceQuery->inDeviceGroup($groupId);
        }

        $deviceIds = $deviceQuery->pluck('devices.device_id')->map(fn ($id): int => (int) $id)->all();

        if (empty($deviceIds)) {
            return $this->render($settings, collect());
        }

        $rows = collect();

        if ($settings['show_type'] === 'all' || $settings['show_type'] === 'devices') {
            $rows = $rows->merge($this->deviceFlaps($deviceIds, $since));
        }

        if ($settings['show_type'] === 'all' || $settings['show_type'] === 'ports') {
            $rows = $rows->merge($this->portFlaps($deviceIds, $since));
        }

        $rows = $rows
            ->filter(fn ($row): bool => (int) $row->changes >= $settings['min_changes'])
            ->sortByDesc(fn ($row) => [(int) $row->changes, (string) $row->last_change])
            ->take($settings['limit'])
            ->values()
            ->map(function ($row) use ($settings) {
                $row->state = $this->stateFromMessage($row->last_message);
                $row->severity = $this->severity((int) $row->changes, $settings['min_changes']);
                $row->short_message = Str::limit((string) $row->last_message, 95);

                return $row;
            });

        return $this->render($settings, $rows);
    }

    private function render(array $settings, Collection $rows): View
    {
        return view('widgets.flapping-devices', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'summary' => [
                'total_changes' => (int) $rows->sum('changes'),
                'devices' => $rows->where('item_type', 'device')->count(),
                'ports' => $rows->where('item_type', 'port')->count(),
                'last_change' => optional($rows->sortByDesc('last_change')->first())->last_change,
            ],
        ]);
    }

    /**
     * Device up/down events, aggregated per device.
     *
     * The newest message is still pulled with GROUP_CONCAT + SUBSTRING_INDEX. Ordering
     * DESC and taking element 1 means group_concat_max_len truncation (1024 bytes by
     * default) trims the OLDEST entries, so the value we actually read is safe unless a
     * single message exceeds the limit on its own.
     *
     * The separator is a string that cannot plausibly occur in an eventlog message; the
     * original used " || ", which a message containing that sequence would have split
     * in the wrong place.
     */
    private function deviceFlaps(array $deviceIds, string $since): Collection
    {
        return DB::table('eventlog as e')
            ->join('devices as d', 'e.device_id', '=', 'd.device_id')
            ->whereIntegerInRaw('e.device_id', $deviceIds)
            ->where('e.datetime', '>=', $since)
            ->where(function ($query): void {
                $query->where('e.type', 'device')
                    ->orWhere('e.message', 'REGEXP', 'Device status|status changed|changed status');
            })
            ->where('e.message', 'REGEXP', 'up|down')
            ->select([
                'e.device_id',
                DB::raw("'device' as item_type"),
                DB::raw('COALESCE(NULLIF(d.display, ""), NULLIF(d.sysName, ""), d.hostname) as device_name'),
                DB::raw('NULL as port_id'),
                DB::raw('NULL as port_name'),
                DB::raw('COUNT(*) as changes'),
                DB::raw('MIN(e.datetime) as first_change'),
                DB::raw('MAX(e.datetime) as last_change'),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(e.message ORDER BY e.datetime DESC SEPARATOR '<<|>>'), '<<|>>', 1) as last_message"),
            ])
            ->groupBy('e.device_id', 'd.display', 'd.sysName', 'd.hostname')
            ->get();
    }

    /**
     * Port up/down events, aggregated per port.
     */
    private function portFlaps(array $deviceIds, string $since): Collection
    {
        return DB::table('eventlog as e')
            ->join('devices as d', 'e.device_id', '=', 'd.device_id')
            ->leftJoin('ports as p', 'p.port_id', '=', 'e.reference')
            ->whereIntegerInRaw('e.device_id', $deviceIds)
            ->where('e.datetime', '>=', $since)
            ->where(function ($query): void {
                $query->where('e.type', 'port')
                    ->orWhere('e.message', 'REGEXP', 'ifOperStatus|oper.*status|link.*up|link.*down|changed.*up|changed.*down');
            })
            ->where('e.message', 'REGEXP', 'up|down')
            ->select([
                'e.device_id',
                DB::raw("'port' as item_type"),
                DB::raw('COALESCE(NULLIF(d.display, ""), NULLIF(d.sysName, ""), d.hostname) as device_name'),
                DB::raw('COALESCE(p.port_id, 0) as port_id'),
                DB::raw('COALESCE(NULLIF(p.ifAlias, ""), NULLIF(p.ifName, ""), NULLIF(p.ifDescr, ""), CONCAT("Port ref ", e.reference)) as port_name'),
                DB::raw('COUNT(*) as changes'),
                DB::raw('MIN(e.datetime) as first_change'),
                DB::raw('MAX(e.datetime) as last_change'),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(e.message ORDER BY e.datetime DESC SEPARATOR '<<|>>'), '<<|>>', 1) as last_message"),
            ])
            ->groupBy(
                'e.device_id',
                'd.display',
                'd.sysName',
                'd.hostname',
                'p.port_id',
                'p.ifAlias',
                'p.ifName',
                'p.ifDescr',
                'e.reference'
            )
            ->get();
    }

    /**
     * Best guess at the state a log line ended in.
     *
     * English and format dependent, inherited from the original.
     */
    private function stateFromMessage(?string $message): string
    {
        $message = (string) $message;

        if (preg_match('/(?:to|now|status[:\s]+)\s+(down|up)/i', $message, $match)) {
            return ucfirst(strtolower($match[1]));
        }

        preg_match_all('/\b(up|down)\b/i', $message, $matches);

        if (! empty($matches[1])) {
            return ucfirst(strtolower((string) end($matches[1])));
        }

        return 'Unknown';
    }

    private function severity(int $changes, int $minChanges): string
    {
        if ($changes >= ($minChanges * 3)) {
            return 'critical';
        }

        if ($changes >= ($minChanges * 2)) {
            return 'warning';
        }

        return 'info';
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $settings['layouts'] = Presentation::layoutsFor($this->name);
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.flapping-devices', $settings);
    }

}
