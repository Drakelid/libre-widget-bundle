<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Facades\LibrenmsConfig;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\MapLayers;
use Illuminate\Http\Request;
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
 * This widget reuses core's own data endpoint (maps.getdevices) and its Leaflet stack,
 * calling the endpoint once per selected group and merging the results. The response is
 * keyed by device_id, so a device in two selected groups appears once.
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
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['status'] = Cast::choice($settings['status'] ?? '0', self::STATUS_MODES, '0');
        $settings['fit_to_markers'] = Cast::bool($settings['fit_to_markers'] ?? true, true);

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
}
