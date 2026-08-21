<?php

namespace Drakelid\NmsDashWidgets\Providers;

use App\Models\Plugin;
use Drakelid\NmsDashWidgets\Hooks\MenuEntry;
use Drakelid\NmsDashWidgets\Hooks\Settings;
use Drakelid\NmsDashWidgets\Support\WidgetCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class WidgetServiceProvider extends ServiceProvider
{
    /**
     * The plugin name. Must match the directory name used by `lnms plugin:add`
     * and the name shown in Overview -> Plugins -> Plugins Admin.
     */
    public const PLUGIN_NAME = 'nmsdashwidgets';

    /**
     * Widget slugs enabled for this installation.
     *
     * Populated during boot() and read by routes/web.php. Static because the routes file
     * is included by the framework rather than by this class.
     *
     * @var list<string>|null
     */
    private static ?array $enabledWidgets = null;

    /**
     * @return list<string>
     */
    public static function enabledWidgets(): array
    {
        return self::$enabledWidgets ?? WidgetCatalog::slugs();
    }

    public function register(): void
    {
        //
    }

    /**
     * Rebuild the route cache when this plugin's settings are saved.
     *
     * The dashboard widget list is built by scanning the route table, and production
     * installations cache that table -- so switching a widget off would otherwise have
     * no visible effect until someone ran route:clear by hand. LibreNMS does the same
     * thing in its own plugin:enable command.
     */
    private function rebuildRoutesWhenSettingsChange(): void
    {
        Plugin::saved(function (Plugin $plugin): void {
            if ($plugin->plugin_name !== self::PLUGIN_NAME || ! $plugin->wasChanged('settings')) {
                return;
            }

            try {
                Artisan::call($this->app->routesAreCached() ? 'route:cache' : 'route:clear');
            } catch (\Throwable $e) {
                // Cache maintenance must never break saving settings; the settings page
                // tells the user how to clear it by hand.
                Log::warning('nmsdashwidgets: could not refresh the route cache', [
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The installed package version, for display on the plugin page.
     *
     * Read from composer's runtime data rather than a hardcoded "version" field in
     * composer.json. That field is discouraged for VCS-published packages precisely
     * because it drifts from the git tag -- v1.1.0 shipped while composer.json still
     * claimed 1.0.1. The git tag is now the single source of truth.
     */
    private function version(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = (string) \Composer\InstalledVersions::getPrettyVersion(
                    'drakelid/librenms-dashboard-widgets'
                );

                return $this->formatVersion($version);
            } catch (\Throwable) {
                // Not installed via composer (development checkout); fall through.
            }
        }

        return 'dev';
    }

    /**
     * Return a display-ready version string.
     *
     * getPrettyVersion() reports the git tag verbatim, so a tag of `v1.1.2` comes back
     * with its own leading "v". The template must therefore NOT add another one -- that
     * produced "vv1.1.2". Prefixing is done here instead, and only for versions that
     * start with a digit, so branch installs stay readable as "dev-main" rather than
     * becoming "vdev-main".
     */
    private function formatVersion(string $version): string
    {
        $version = trim($version);

        if ($version === '') {
            return 'dev';
        }

        // Normalise away any leading v, then add exactly one back for numeric versions.
        $bare = preg_replace('/^v(?=\d)/i', '', $version) ?? $version;

        return preg_match('/^\d/', $bare) === 1 ? 'v' . $bare : $bare;
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
        $pluginManager->publishHook(self::PLUGIN_NAME, SettingsHook::class, Settings::class);

        if (! $pluginManager->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        // Which widgets the administrator has switched on. Read once here and stashed so
        // routes/web.php can register only those. See enabledWidgets().
        self::$enabledWidgets = WidgetCatalog::enabled($pluginManager->getSettings(self::PLUGIN_NAME));

        $this->rebuildRoutesWhenSettingsChange();

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
