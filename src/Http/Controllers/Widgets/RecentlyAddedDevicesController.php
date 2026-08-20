<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Device;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Devices most recently added to LibreNMS, ordered by devices.inserted.
 */
class RecentlyAddedDevicesController extends BundleWidgetController
{
    protected string $name = 'recently-added-devices';

    protected $defaults = [
        'title' => null,
        'device_count' => 10,
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);

        // The settings form offers 1-50; the original controller only enforced a
        // lower bound, so a hand-edited blob could ask for thousands of rows.
        $settings['device_count'] = Cast::clampedInt($settings['device_count'] ?? 10, 1, 50, 10);

        return $settings;
    }

    public function getView(Request $request): string|View
    {
        $settings = $this->settings();

        // All columns are selected deliberately: <x-device-link> reads a wide set of
        // device attributes (icon, os, display, status...) and the row count is capped
        // at 50, so there is nothing to gain from narrowing this.
        $devices = Device::hasAccess($request->user())
            ->orderByDesc('inserted')
            ->orderByDesc('device_id')
            ->limit($settings['device_count'])
            ->get();

        return view('widgets.recently-added-devices', $settings + $this->shared($settings) + [
            'devices' => $devices,
        ]);
    }
}
