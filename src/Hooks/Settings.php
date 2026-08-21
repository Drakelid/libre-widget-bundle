<?php

namespace Drakelid\NmsDashWidgets\Hooks;

use Drakelid\NmsDashWidgets\Support\WidgetCatalog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
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
     * Only plugin administrators may see or change which widgets exist.
     *
     * This is defence in depth. LibreNMS already gates the page twice -- the route
     * carries can:plugin.admin middleware, and PluginSettingsController calls
     * authorize('plugin.admin') on both the GET and the POST that saves. The check here
     * means a future change to either of those cannot silently expose the form.
     *
     * DO NOT add a User parameter to this method.
     *
     * PluginManager invokes it through the service container
     * (app()->call([$instance, 'authorize'], ...)), and LibreNMS does not bind
     * App\Models\User. The container would satisfy such a parameter by constructing a
     * brand new, empty User on which every can() check fails -- the hook is then
     * filtered out of hooksFor(), call() returns an empty array, and
     * PluginSettingsController falls back to its 'plugins.missing' view. The symptom is
     * a blank settings page, with nothing in the log because nothing threw. Core's own
     * hook base classes carry that signature and get away with it only because their
     * default implementation returns true without consulting the user.
     *
     * The Auth facade resolves the genuinely authenticated user, so this works.
     */
    public function authorize(): bool
    {
        $user = Auth::user();

        // 'plugin.admin' is a spatie permission. AppServiceProvider's Gate::before
        // grants every ability to the admin role, so administrators always pass.
        return $user !== null && $user->can('plugin.admin');
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
