<?php

namespace Drakelid\NmsDashWidgets\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * Adds a menu entry pointing at the plugin's information page.
 *
 * MenuEntryHook is an INTERFACE (a marker extending LibreNMS\Interfaces\Plugins\Hook),
 * so this class implements it -- it cannot extend it. The LibreNMS plugin docs show an
 * `extends` form with a `data()` method, which does not match the shipped
 * librenms/plugin-interfaces package; `implements` with authorize()/handle() is what
 * actually works.
 *
 * Both methods are resolved by the plugin manager through dependency injection, which
 * is why their signatures differ from a plain interface contract (Hook itself declares
 * no methods).
 */
class MenuEntry implements MenuEntryHook
{
    /**
     * Whether the current user may see the menu entry.
     *
     * The page it links to is admin gated by PluginAdminController, but the entry
     * itself is harmless for any authenticated user.
     */
    public function authorize(Authenticatable $user, array $settings = []): bool
    {
        return true;
    }

    /**
     * The view to render for this menu entry, plus any data it needs.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
