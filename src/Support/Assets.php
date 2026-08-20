<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Static assets shipped with the plugin.
 *
 * Widgets are delivered as HTML fragments over AJAX and injected into the dashboard,
 * so there is no layout to hook a stylesheet into. The original widgets each carried
 * their own inline <style> block, which meant every widget instance re-emitted its
 * own copy of near-identical CSS.
 *
 * Here a single stylesheet is shared by all six widgets and injected into <head>
 * exactly once per page (see partials/nmsdw-style.blade.php), so additional widget
 * instances and refreshes cost nothing.
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
