<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\DeviceGroup;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Down device counts per selected device group.
 *
 * Two behaviours carried over deliberately from the original:
 *
 *  - An empty device group selection shows NOTHING, it does not fall back to "all
 *    groups". Users rely on this to keep the widget quiet until configured.
 *  - The grand total is the SUM of per-group counts, so a device in two selected
 *    groups is counted twice. Changing it to a distinct count would be a behaviour
 *    change; see README.
 */
class DeviceGroupDownCountController extends BundleWidgetController
{
    protected string $name = 'device-group-down-count';

    /**
     * Display modes. `auto`, `cards`, `compact`, `list` and `summary` are the original
     * five and must keep working -- they are present in saved widget settings.
     * `tiles` and `bars` were added later; unknown values fall back to `auto`.
     */
    public const DISPLAY_MODES = ['auto', 'cards', 'compact', 'list', 'summary', 'tiles', 'bars'];
    public const DENSITIES = ['comfortable', 'compact'];
    public const SORTS = ['selection', 'most_down', 'percent', 'name'];

    protected $defaults = [
        'title' => null,
        // Overrides the heading inside the widget body; the top title bar is `title`.
        'heading' => null,
        'refresh' => 60,
        'device_groups' => [],
        'background_color' => '#d9534f',
        'text_color' => '#ffffff',
        'show_total' => '1',
        'show_header' => '1',
        'show_group_totals' => '1',
        'display_mode' => 'auto',
        'density' => 'comfortable',
        'card_min_width' => 170,
        // 1 = ignore disabled/ignored devices, 0 = count every device with status = 0.
        'exclude_ignored_disabled' => '1',
        // Added later; defaults preserve the original behaviour exactly.
        'sort' => 'selection',
        'hide_healthy' => '0',
        'accent' => 'default',
        'zebra' => '0',
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['heading'] = Cast::nullableString($settings['heading'] ?? null);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['background_color'] = Cast::color($settings['background_color'] ?? null, '#d9534f');
        $settings['text_color'] = Cast::color($settings['text_color'] ?? null, '#ffffff');
        $settings['show_total'] = Cast::bool($settings['show_total'] ?? true, true);
        $settings['show_header'] = Cast::bool($settings['show_header'] ?? true, true);
        $settings['show_group_totals'] = Cast::bool($settings['show_group_totals'] ?? true, true);
        $settings['exclude_ignored_disabled'] = Cast::bool($settings['exclude_ignored_disabled'] ?? true, true);
        $settings['display_mode'] = Cast::choice($settings['display_mode'] ?? 'auto', self::DISPLAY_MODES, 'auto');
        $settings['density'] = Cast::choice($settings['density'] ?? 'comfortable', self::DENSITIES, 'comfortable');
        $settings['card_min_width'] = Cast::int($settings['card_min_width'] ?? 170, 120, 320, 170);
        $settings['sort'] = Cast::choice($settings['sort'] ?? 'selection', self::SORTS, 'selection');
        $settings['hide_healthy'] = Cast::bool($settings['hide_healthy'] ?? false, false);
        $settings['accent'] = Cast::choice($settings['accent'] ?? null, Presentation::ACCENTS, 'default');
        $settings['zebra'] = Cast::bool($settings['zebra'] ?? false, false);

        return $settings;
    }

    public function getView(Request $request): View|string
    {
        $settings = $this->settings();
        $user = $request->user();

        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);
        $excludeIgnoredDisabled = $settings['exclude_ignored_disabled'];

        $groups = collect();

        if (! empty($groupIds)) {
            // Both counts come back in one query via conditional aggregation rather
            // than a query per group.
            $groups = DeviceGroup::hasAccess($user)
                ->whereIntegerInRaw('id', $groupIds)
                ->withCount([
                    'devices as total_count' => function ($query) use ($user, $excludeIgnoredDisabled): void {
                        $query->hasAccess($user);

                        if ($excludeIgnoredDisabled) {
                            $query->where('devices.disabled', 0)->where('devices.ignore', 0);
                        }
                    },
                    'devices as down_count' => function ($query) use ($user, $excludeIgnoredDisabled): void {
                        $query->hasAccess($user)->where('devices.status', 0);

                        if ($excludeIgnoredDisabled) {
                            $query->where('devices.disabled', 0)->where('devices.ignore', 0);
                        }
                    },
                ])
                ->get()
                // Display in the order the user picked the groups, not alphabetically.
                ->sortBy(fn ($group) => array_search((int) $group->id, $groupIds, true))
                ->values();
        }

        // Totals are taken from the FULL set before any display filtering, so hiding
        // healthy groups never changes the numbers in the banner or the header.
        $totalDown = (int) $groups->sum('down_count');
        $totalDevices = (int) $groups->sum('total_count');
        $affected = $groups->where('down_count', '>', 0)->count();

        // Proportion matters as much as the raw count: 2 of 2 down is an outage,
        // 22 of 500 is a bad afternoon. Layouts use this to size their bars.
        $groups = $groups->map(function ($group) {
            $total = max(0, (int) $group->total_count);
            $down = max(0, (int) $group->down_count);

            $group->healthy_count = max(0, $total - $down);
            $group->down_percent = $total > 0 ? ($down / $total) * 100 : 0.0;

            // The row bar reads as a health meter: full means everything is up. A group
            // with no devices counts as healthy rather than showing an empty bar.
            $group->health_percent = $total > 0 ? ($group->healthy_count / $total) * 100 : 100.0;

            return $group;
        });

        $visible = $this->sortGroups($groups, $settings['sort'], $groupIds);

        if ($settings['hide_healthy']) {
            $visible = $visible->where('down_count', '>', 0)->values();
        }

        $layout = $this->layoutFor($request, $settings['display_mode']);

        return view('widgets.device-group-down-count', $settings + $this->shared($settings) + [
            'groups' => $visible,
            'group_count' => $groups->count(),
            'hidden_count' => $groups->count() - $visible->count(),
            'total_down' => $totalDown,
            'total_devices' => $totalDevices,
            'affected_groups' => $affected,
            'worst_group' => $groups->sortByDesc('down_count')->first(),
            'layout' => $layout,
            'has_selection' => ! empty($groupIds),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\DeviceGroup>  $groups
     * @param  list<int>  $groupIds
     * @return \Illuminate\Support\Collection<int, \App\Models\DeviceGroup>
     */
    private function sortGroups($groups, string $sort, array $groupIds)
    {
        return match ($sort) {
            // Worst first by absolute count, then by proportion as a tie-break.
            'most_down' => $groups->sortByDesc(fn ($g) => [(int) $g->down_count, $g->down_percent])->values(),
            'percent' => $groups->sortByDesc(fn ($g) => [$g->down_percent, (int) $g->down_count])->values(),
            'name' => $groups->sortBy(fn ($g) => mb_strtolower((string) $g->name))->values(),
            // Default: the order the user picked the groups in.
            default => $groups->sortBy(fn ($g) => array_search((int) $g->id, $groupIds, true))->values(),
        };
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);

        return view('widgets.settings.device-group-down-count', array_merge($settings, [
            'selected_device_groups' => DeviceGroups::ordered($request->user(), $groupIds),
            'background_color' => Cast::color($settings['background_color'] ?? null, '#d9534f'),
            'text_color' => Cast::color($settings['text_color'] ?? null, '#ffffff'),
            'display_mode' => Cast::choice($settings['display_mode'] ?? 'auto', self::DISPLAY_MODES, 'auto'),
            'density' => Cast::choice($settings['density'] ?? 'comfortable', self::DENSITIES, 'comfortable'),
            'card_min_width' => Cast::int($settings['card_min_width'] ?? 170, 120, 320, 170),
            'show_total' => Cast::bool($settings['show_total'] ?? true, true),
            'show_header' => Cast::bool($settings['show_header'] ?? true, true),
            'show_group_totals' => Cast::bool($settings['show_group_totals'] ?? true, true),
            'exclude_ignored_disabled' => Cast::bool($settings['exclude_ignored_disabled'] ?? true, true),
            'sort' => Cast::choice($settings['sort'] ?? 'selection', self::SORTS, 'selection'),
            'heading' => Cast::nullableString($settings['heading'] ?? null),
            'accent' => Cast::choice($settings['accent'] ?? null, Presentation::ACCENTS, 'default'),
            'zebra' => Cast::bool($settings['zebra'] ?? false, false),
            'hide_healthy' => Cast::bool($settings['hide_healthy'] ?? false, false),
        ]));
    }
}
