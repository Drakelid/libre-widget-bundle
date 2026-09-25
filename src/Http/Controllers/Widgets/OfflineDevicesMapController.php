<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\UserWidget;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\MapLayers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use LibreNMS\Util\Url;
use Illuminate\View\View;

/**
 * Geographic map of devices, filtered by any number of device groups.
 *
 * WHY THIS EXISTS
 *
 * LibreNMS ships a World Map widget and it is good, but its device group setting is a
 * single scalar -- `(int) $device_group` in the blade, and `where('device_group_id',
 * $group_id)` in the map data endpoint. There is no way to show two groups at once.
 *
 * This widget reuses core's Leaflet stack and returns one access-filtered snapshot
 * for both markers and the outage list, including devices without coordinates.
 *
 * It also defaults to showing DOWN devices only, which is the usual reason to put a map
 * on a NOC dashboard.
 */
class OfflineDevicesMapController extends BundleWidgetController
{
    protected string $name = 'offline-devices-map';

    /** Which device states to plot. Values match core's `statuses` parameter. */
    public const STATUS_MODES = ['0', '0,1', '1'];

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        // Down only: this is an outage map, not an inventory map.
        'status' => '0',
        'init_lat' => null,
        'init_lng' => null,
        'init_zoom' => null,
        'init_layer' => null,
        'group_radius' => null,
        'fit_to_markers' => '1',
        // Attribution is hidden by default: on a NOC dashboard the map tile is small
        // and the credit line eats a visible slice of it. Operators who need to honour
        // a tile provider's attribution terms can turn this off per widget.
        'hide_attribution' => '1',
        'hide_zoom' => '0',
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['status'] = Cast::choice($settings['status'] ?? '0', self::STATUS_MODES, '0');
        $settings['fit_to_markers'] = Cast::bool($settings['fit_to_markers'] ?? true, true);
        $settings['hide_attribution'] = Cast::bool($settings['hide_attribution'] ?? true, true);
        $settings['hide_zoom'] = Cast::bool($settings['hide_zoom'] ?? false, false);

        // Null means "use the LibreNMS default", resolved in getView().
        $settings['init_lat'] = is_numeric($settings['init_lat'] ?? null) ? (float) $settings['init_lat'] : null;
        $settings['init_lng'] = is_numeric($settings['init_lng'] ?? null) ? (float) $settings['init_lng'] : null;
        $settings['init_zoom'] = is_numeric($settings['init_zoom'] ?? null) ? (float) $settings['init_zoom'] : null;
        $settings['init_layer'] = Cast::nullableString($settings['init_layer'] ?? null);
        $settings['group_radius'] = is_numeric($settings['group_radius'] ?? null)
            ? Cast::clampedInt($settings['group_radius'], 1, 500, 10)
            : null;

        return $settings;
    }

    public function getView(Request $request): string|View
    {
        $settings = $this->settings();
        $user = $request->user();

        // Group ids are re-checked against what the user may see; the map endpoint
        // applies its own device permissions on top of that.
        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);

        $settings['group_ids'] = $groupIds;
        $settings['group_label'] = DeviceGroups::namesFor($user, $groupIds, __('All accessible devices'));

        // Same shape core's WorldMapController builds, so init_map() behaves identically.
        $settings['map_config'] = [
            'engine' => LibrenmsConfig::get('geoloc.engine'),
            'api_key' => LibrenmsConfig::get('geoloc.api_key'),
            'tile_url' => LibrenmsConfig::get('leaflet.tile_url'),
            'lat' => $settings['init_lat'] ?? LibrenmsConfig::get('leaflet.default_lat'),
            'lng' => $settings['init_lng'] ?? LibrenmsConfig::get('leaflet.default_lng'),
            'zoom' => $settings['init_zoom'] ?? LibrenmsConfig::get('leaflet.default_zoom'),
            'layer' => $settings['init_layer'] ?? LibrenmsConfig::get('geoloc.layer'),
        ];

        $settings['statuses'] = array_map(intval(...), explode(',', (string) $settings['status']));
        $settings['radius'] = $settings['group_radius'] ?? (int) LibrenmsConfig::get('leaflet.group_radius');

        // null includes devices with notifications disabled; 0 excludes them.
        $settings['disabled_alerts'] = LibrenmsConfig::get('network_map_worldmap_show_disabled_alerts') ? null : 0;

        return view('widgets.offline-devices-map', $settings + $this->shared($settings));
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        // Layer choice depends on the configured mapping engine, not on this widget.
        // Offering options the installation cannot honour is how the layer setting came
        // to look broken.
        $settings['available_layers'] = MapLayers::available();
        $settings['map_engine'] = MapLayers::engine();

        return view('widgets.settings.offline-devices-map', $settings);
    }

    /** A complete permission-filtered snapshot keeps the map and outage list in sync. */
    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);
        $request->validate(['id' => 'required|integer|min:1']);
        $widget = UserWidget::findOrFail((int) $request->input('id'));
        abort_unless($widget->widget === $this->name, 404);
        abort_unless($widget->dashboard && $request->user()->can('view', $widget->dashboard), 403);
        $settings = $this->settings();
        $groupIds = DeviceGroups::accessibleIds($request->user(), $settings['device_groups']);
        $query = Device::hasAccess($request->user())->with('location')->where('disabled', 0)
            ->whereIn('status', array_map('intval', explode(',', $settings['status'])));
        if ($settings['device_groups'] && ! $groupIds) {
            $query->whereRaw('1 = 0');
        }
        DeviceGroups::scopeToDevices($query, $groupIds);
        if (! LibrenmsConfig::get('network_map_worldmap_show_disabled_alerts')) {
            $query->where('disable_notify', 0);
        }
        $types = collect(LibrenmsConfig::get('device_types', []))->pluck('icon', 'type');
        $devices = $query->orderBy('device_id')->get()->mapWithKeys(function ($device) use ($types) {
            $lat = $device->location?->lat;
            $lng = $device->location?->lng;
            $valid = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
            return [$device->device_id => [
                'id' => (int) $device->device_id,
                'sname' => $device->displayName(),
                'url' => Url::deviceUrl($device),
                'typeIcon' => $types->get($device->type, 'server'),
                'status' => (bool) $device->status,
                'maintenance' => $device->isUnderMaintenance() ? 1 : 0,
                'lat' => $valid ? (float) $lat : null,
                'lng' => $valid ? (float) $lng : null,
                'site' => $device->location?->location ?: __('Unknown location'),
                'last_polled' => $device->last_polled?->toIso8601String(),
                'outage_seconds' => ! $device->status && $device->last_polled ? max(0, (int) $device->last_polled->diffInSeconds(now(), true)) : null,
            ]];
        });
        return response()->json(['devices' => (object) $devices->all(), 'observed_at' => now()->toIso8601String()]);
    }
}
