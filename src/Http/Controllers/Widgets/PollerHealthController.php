<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\PollerCluster;
use App\Models\PollerGroup;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Devices whose data has gone stale, and pollers that have stopped reporting.
 *
 * On a large estate a silently stale dashboard is more dangerous than a blank one: an
 * availability widget showing all-green because polling stopped an hour ago is actively
 * misleading. Core's server-stats widget reports on the LibreNMS host itself, not on
 * whether the data behind every other widget is still fresh.
 */
class PollerHealthController extends BundleWidgetController
{
    protected string $name = 'poller-health';

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        'stale_minutes' => 15,
        'limit' => 25,
        'show_pollers' => true,
        'ignore_disabled' => true,

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
        $settings['stale_minutes'] = Cast::clampedInt($settings['stale_minutes'] ?? 15, 1, 10080, 15);
        $settings['limit'] = Cast::clampedInt($settings['limit'] ?? 25, 1, 200, 25);
        $settings['show_pollers'] = Cast::bool($settings['show_pollers'] ?? true, true);
        $settings['ignore_disabled'] = Cast::bool($settings['ignore_disabled'] ?? true, true);

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
        $cutoff = Carbon::now()->subMinutes($settings['stale_minutes']);

        $base = Device::hasAccess($user)
            ->when($settings['ignore_disabled'], fn ($q) => $q->where('disabled', 0)->where('ignore', 0));

        DeviceGroups::scopeToDevices($base, $groupIds, 'devices.device_id');

        $total = (clone $base)->count();

        // Never polled is a different problem from stopped polling; count separately.
        $neverPolled = (clone $base)->whereNull('last_polled')->count();

        $staleQuery = (clone $base)
            ->where(fn ($q) => $q->whereNull('last_polled')->orWhere('last_polled', '<', $cutoff))
            ->orderByRaw('last_polled IS NULL DESC')
            ->orderBy('last_polled');

        $staleCount = (clone $staleQuery)->count();
        $devices = (clone $staleQuery)->limit($settings['limit'])->get();

        $mayViewPollers = $user->can('viewAny', PollerCluster::class);
        $inventory = $mayViewPollers ? $this->pollers() : collect();
        $pollers = $inventory ?? collect();
        $groupNames = $user->can('viewAny', PollerGroup::class)
            ? PollerGroup::query()->whereIn('id', $devices->pluck('poller_group')->unique())->pluck('group_name', 'id')
            : collect();
        $staleGroups = (clone $staleQuery)->reorder()->select('poller_group')->selectRaw('COUNT(*) AS total')->groupBy('poller_group')->pluck('total', 'poller_group');
        $rows = $devices->map(function (Device $device) use ($pollers, $groupNames): array {
            $last = $device->last_polled;

            $group = (int) $device->poller_group;
            $assigned = $pollers->filter(fn (array $p): bool => in_array($group, $p['groups'], true) && $p['enabled']);
            $intervals = $assigned->pluck('interval')->unique();
            // Multiple pollers can serve one group. Keep an explicit range rather than inventing a single interval.
            $intervalMin = (int) ($intervals->min() ?? LibrenmsConfig::get('service_poller_frequency', 300));
            $intervalMax = (int) ($intervals->max() ?? $intervalMin);
            return [
                'device' => $device,
                'group_id' => $group,
                'group_label' => $groupNames->get($group) ?? __('Poller group :id', ['id' => $group]),
                'interval' => $intervalMin === $intervalMax ? (string) $intervalMin : $intervalMin . ' - ' . $intervalMax,
                'duration' => is_numeric($device->last_polled_timetaken) ? (float) $device->last_polled_timetaken : null,
                'overrun' => is_numeric($device->last_polled_timetaken) && (float) $device->last_polled_timetaken > $intervalMin,
                'disabled' => (bool) $device->disabled,
                'observed_at' => $last,
                'last_polled' => $last,
                'stale_for' => $last ? $last->diffForHumans(null, true) : null,
            ];
        })->all();

        return view('widgets.poller-health', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'summary' => [
                'total' => $total,
                'stale' => $staleCount,
                'never_polled' => $neverPolled,
                'fresh' => max(0, $total - $staleCount),
            ],
            'pollers' => $settings['show_pollers'] ? $pollers : collect(),
            'poller_inventory_unavailable' => $mayViewPollers && $inventory === null,
            'stale_groups' => $staleGroups,
            'matched_count' => $staleCount,
            'cutoff' => $cutoff,
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
        ]);
    }

    /**
     * Poller nodes and whether each is still checking in.
     *
     * PollerCluster::isInactive() compares last_report against each node's own
     * poller_frequency, so a slow poller is not flagged simply for being slow.
     * Guarded: a single-node install without the poller-cluster feature has no rows,
     * and an older schema should not break the whole widget.
     */
    private function pollers()
    {
        try {
            return PollerCluster::query()
                ->orderBy('poller_name')
                ->get()
                ->map(fn (PollerCluster $p): array => $this->pollerSummary($p));
        } catch (\Throwable) {
            return null;
        }
    }

    private function pollerSummary(PollerCluster $poller): array
    {
        $groups = $poller->getSettingValue('poller_groups');

        return [
            'name' => $poller->poller_name,
            'node_id' => $poller->node_id,
            'version' => $poller->poller_version,
            'last_report' => $poller->last_report,
            'enabled' => (bool) $poller->getSettingValue('poller_enabled'),
            'interval' => max(1, (int) $poller->getSettingValue('poller_frequency')),
            'groups' => array_map('intval', is_array($groups) ? $groups : explode(',', (string) $groups)),
            'active' => $this->pollerIsActive($poller),
        ];
    }

    private function pollerIsActive(PollerCluster $poller): bool
    {
        $lastReport = $poller->last_report;

        if ($lastReport === null) {
            return false;
        }

        // Two intervals of grace before calling a poller dead.
        $frequency = (int) ($poller->poller_frequency ?? LibrenmsConfig::get('service_poller_frequency', 300));

        return Carbon::parse($lastReport)->greaterThan(Carbon::now()->subSeconds(max(60, $frequency * 2)));
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.poller-health', $settings);
    }
}
