<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Static assets shipped with the plugin.
 *
 * Widgets are delivered as HTML fragments over AJAX. The dashboard view composer
 * loads this stylesheet for all widgets, including core widgets. The fragment
 * partial still loads it when a widget is rendered outside that dashboard view.
 *
 * A single style element is shared across all twelve plugin widgets and dashboard
 * scrollbars, so additional widget instances and refreshes cost nothing.
 */
final class Assets
{
    private static ?string $css = null;

    /** The bundle stylesheet, read once per request. */
    public static function css(): string
    {
        if (self::$css === null) {
            $path = __DIR__ . '/../../resources/css/widgets.css';
            $contents = is_readable($path) ? file_get_contents($path) : '';
            self::$css = $contents === false ? '' : $contents;
        }

        return self::$css;
    }

    /** Identifier of the injected <style> element, used to avoid duplicates. */
    public static function styleElementId(): string
    {
        return 'nmsdw-styles';
    }
}
