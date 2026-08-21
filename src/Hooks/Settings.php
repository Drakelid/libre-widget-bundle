<?php

namespace Drakelid\NmsDashWidgets\Hooks;

use App\Models\User;
use Drakelid\NmsDashWidgets\Support\WidgetCatalog;
use Illuminate\Contracts\Foundation\Application;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings page shown under Overview -> Plugins -> Plugins Admin -> Settings.
 *
 * Lets an administrator choose which of the bundle's widgets are available.
 *
 * SettingsHook is a marker interface, so handle() and authorize() are implemented
 * directly here rather than inherited. Core's PluginSettingsController calls handle()
 * and expects back a `content_view` plus a `settings` array, which it passes to
 * resources/views/plugins/settings.blade.php as the include data.
 */
class Settings implements SettingsHook
{
    /** Only plugin administrators may change which widgets exist. */
    public function authorize(User $user): bool
    {
        return $user->can('plugin.admin');
    }

    /**
     * @param  array<string, mixed>  $settings  the plugin's stored settings
     * @return array{content_view: string, settings: array<string, mixed>}
     */
    public function handle(string $pluginName, array $settings = [], ?Application $app = null): array
    {
        return [
            'content_view' => $pluginName . '::settings',
            'settings' => [
                'plugin_name' => $pluginName,
                'widgets' => WidgetCatalog::all(),
                'enabled' => WidgetCatalog::enabled($settings),
                'setting_key' => WidgetCatalog::SETTING,
                // Routes are cached in production and the widget picker is built by
                // scanning them, so a change here only takes effect once the cache is
                // rebuilt. The provider does that on save; the view says so in case it
                // could not.
                'routes_cached' => $app?->routesAreCached() ?? false,
            ],
        ];
    }
}
