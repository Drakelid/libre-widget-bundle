<?php

namespace Drakelid\NmsDashWidgets\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * Minimal menu entry. The widgets are the product; this just gives the plugin a
 * discoverable page describing what it provides.
 */
class MenuEntry extends MenuEntryHook
{
    public function data(array $settings = []): array
    {
        return [
            'title' => __('Dashboard Widget Bundle'),
        ];
    }
}
