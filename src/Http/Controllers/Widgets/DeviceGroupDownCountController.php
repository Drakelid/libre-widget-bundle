<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\DeviceGroup;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
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

    public const DISPLAY_MODES = ['auto', 'cards', 'compact', 'list', 'summary'];
    public const DENSITIES = ['comfortable', 'compact'];

    protected $defaults = [
        'title' => null,
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
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
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

        $layout = $this->layoutFor($request, $settings['display_mode']);

        return view('widgets.device-group-down-count', $settings + $this->shared($settings) + [
            'groups' => $groups,
            'total_down' => (int) $groups->sum('down_count'),
            'affected_groups' => $groups->where('down_count', '>', 0)->count(),
            'layout' => $layout,
            'has_selection' => ! empty($groupIds),
        ]);
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
        ]));
    }
}
