<?php

namespace Drakelid\NmsDashWidgets\Providers;

use Drakelid\NmsDashWidgets\Hooks\MenuEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class WidgetServiceProvider extends ServiceProvider
{
    /**
     * The plugin name. Must match the directory name used by `lnms plugin:add`
     * and the name shown in Overview -> Plugins -> Plugins Admin.
     */
    public const PLUGIN_NAME = 'nmsdashwidgets';

    public function register(): void
    {
        //
    }

    private function version(): string
    {
        $composerFile = __DIR__ . '/../../composer.json';

        if (! is_readable($composerFile)) {
            return 'unknown';
        }

        $data = json_decode((string) file_get_contents($composerFile), true);

        return is_array($data) ? ($data['version'] ?? 'unknown') : 'unknown';
    }

    /**
     * Boot the plugin, and never take LibreNMS down doing it.
     *
     * Laravel registers this provider on EVERY request via composer auto-discovery.
     * Anything that escapes this method becomes a site-wide 500, not a broken widget.
     * A dashboard plugin failing must degrade to "widgets missing", never to
     * "LibreNMS unavailable", so every throwable is caught and logged here.
     *
     * This cannot catch a fatal raised while COMPILING one of our classes (an invalid
     * class declaration, for example). That class of bug is caught before release by
     * the class-load smoke test in tests/load-check.php, which CI runs on every push.
     */
    public function boot(PluginManagerInterface $pluginManager): void
    {
        try {
            $this->bootPlugin($pluginManager);
        } catch (\Throwable $e) {
            $this->reportBootFailure($e);
        }
    }

    private function reportBootFailure(\Throwable $e): void
    {
        try {
            Log::error('nmsdashwidgets: plugin failed to boot and was skipped', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (\Throwable) {
            // Logging must never be the thing that breaks the request either.
        }
    }

    private function bootPlugin(PluginManagerInterface $pluginManager): void
    {
        // Hooks must be published whether or not the plugin is enabled, so the
        // plugin can be discovered and toggled in Plugins Admin.
        $pluginManager->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, MenuEntry::class);

        if (! $pluginManager->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        $views = __DIR__ . '/../../resources/views';

        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');

        // Namespaced views, used for plugin pages and shared partials.
        $this->loadViewsFrom($views, self::PLUGIN_NAME);

        /*
         * Widget views are ALSO registered as an additional root location.
         *
         * This is deliberate and load bearing. LibreNMS decides whether a widget
         * response contains the settings form by string matching the view name:
         *
         *     $show_settings = (int) Str::startsWith($view->getName(), 'widgets.settings.');
         *
         * A namespaced view name ("nmsdashwidgets::widgets.settings.foo") fails that
         * test, which makes the dashboard toggle stick in the settings form and never
         * return to the widget body. Registering the directory as a root location lets
         * us resolve "widgets.settings.<slug>" with an un-namespaced name.
         *
         * Core view paths are searched before locations added here, so this cannot
         * shadow a core view.
         */
        View::getFinder()->addLocation($views);

        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', self::PLUGIN_NAME);

        // Make the package version available to the plugin's own pages. Registered in
        // boot() rather than register() so the view factory is guaranteed to exist.
        View::composer(self::PLUGIN_NAME . '::*', function ($view): void {
            $view->with('nmsdashwidgets_version', $this->version());
        });

        /*
         * No migrations are loaded on purpose. This plugin creates no schema, and the
         * one data migration it needs -- retiring the old `group-world-map` widget --
         * rewrites rows in core's `users_widgets` table. Doing that silently on install
         * would be a surprising side effect, so it ships as an opt-in file under
         * database/migrations-optional/ instead. See docs/RETIRE-GROUP-WORLD-MAP.md.
         */
    }
}
