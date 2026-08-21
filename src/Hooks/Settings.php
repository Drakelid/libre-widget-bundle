<?php

namespace Drakelid\NmsDashWidgets\Hooks;

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
    /**
     * Whether this hook should run.
     *
     * DO NOT type-hint App\Models\User here.
     *
     * PluginManager invokes this through the service container
     * (`app()->call([$instance, 'authorize'], ...)`), and LibreNMS does not bind
     * App\Models\User. The container therefore satisfies such a parameter by
     * constructing a brand new, empty User -- on which every can() check fails. The
     * hook is then filtered out of hooksFor(), call() returns an empty array, and
     * PluginSettingsController falls back to its 'plugins.missing' view. The symptom
     * is a blank settings page reading "missing view", with nothing in the log.
     *
     * Core's own hook base classes use that signature and get away with it only
     * because their default implementation returns true without consulting the user.
     *
     * Authorisation is not being skipped: PluginSettingsController calls
     * $this->authorize('plugin.admin') before this hook is ever reached, and the route
     * carries can:plugin.admin middleware.
     */
    public function authorize(): bool
    {
        return true;
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
