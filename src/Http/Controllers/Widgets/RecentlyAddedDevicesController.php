<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Device;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
use Drakelid\NmsDashWidgets\Support\Presentation;
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

        // The settings form offers 1-50; the original controller only enforced a
        // lower bound, so a hand-edited blob could ask for thousands of rows.
        $settings['device_count'] = Cast::clampedInt($settings['device_count'] ?? 10, 1, 50, 10);

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

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $settings['layouts'] = Presentation::layoutsFor($this->name);
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.recently-added-devices', $settings);
    }

}
