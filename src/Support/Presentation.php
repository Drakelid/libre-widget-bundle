<?php

namespace Drakelid\NmsDashWidgets\Support;

use Illuminate\Http\Request;

/**
 * Shared layout and styling settings.
 *
 * Every widget in the bundle exposes the same presentation controls -- layout,
 * density, accent colour, zebra striping, in-body header -- so a dashboard can be
 * tuned as a whole rather than each widget having its own vocabulary.
 *
 * Defaults always reproduce the layout the widget shipped with, so adding these
 * settings never changes an existing placement.
 */
final class Presentation
{
    public const DENSITIES = ['comfortable', 'compact'];

    /** Accents recolour neutral chrome. Status colours stay semantic. */
    public const ACCENTS = ['default', 'blue', 'green', 'amber', 'red', 'violet', 'slate'];

    /**
     * Layouts each widget offers, first entry being its original look.
     *
     * `table`   dense columnar view, the default for list-shaped widgets
     * `cards`   one card per record, good for wide widgets and wall displays
     * `compact` one dense line per record
     * `rows`    full-width status rows, the default for the sensor widgets
     * `tiles`   colour-coded squares, dense status at a glance
     */
    public const LAYOUTS = [
        'top-bandwidth-device-group' => ['table', 'cards', 'compact'],
        'uplink-utilization-overview' => ['table', 'cards', 'compact'],
        'optical-light-levels' => ['table', 'cards', 'compact'],
        'bgp-session-health' => ['table', 'cards', 'compact'],
        'customer-port-status' => ['table', 'cards', 'compact'],
        'poller-health' => ['table', 'cards', 'compact'],
        'recently-added-devices' => ['table', 'cards', 'compact'],
        'flapping-devices' => ['table', 'cards', 'compact'],
        'top-device-temperatures' => ['rows', 'cards', 'compact', 'tiles'],
        'site-power-status' => ['rows', 'cards', 'compact', 'tiles'],
    ];

    /**
     * Settings to merge into a widget's $defaults.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $slug): array
    {
        return [
            'layout' => self::defaultLayout($slug),
            'density' => 'comfortable',
            'accent' => 'default',
            'zebra' => '0',
            'show_header' => '1',
            'card_min_width' => 220,
        ];
    }

    public static function defaultLayout(string $slug): string
    {
        return self::layoutsFor($slug)[0] ?? 'table';
    }

    /**
     * @return list<string>
     */
    public static function layoutsFor(string $slug): array
    {
        return self::LAYOUTS[$slug] ?? ['table', 'cards', 'compact'];
    }

    /**
     * Coerce the presentation keys of a settings array.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings, string $slug): array
    {
        $layouts = self::layoutsFor($slug);

        // 'auto' is accepted for every widget and resolved at render time.
        $allowed = array_merge(['auto'], $layouts);

        $settings['layout'] = Cast::choice($settings['layout'] ?? null, $allowed, self::defaultLayout($slug));
        $settings['density'] = Cast::choice($settings['density'] ?? null, self::DENSITIES, 'comfortable');
        $settings['accent'] = Cast::choice($settings['accent'] ?? null, self::ACCENTS, 'default');
        $settings['zebra'] = Cast::bool($settings['zebra'] ?? false, false);
        $settings['show_header'] = Cast::bool($settings['show_header'] ?? true, true);
        $settings['card_min_width'] = Cast::int($settings['card_min_width'] ?? 220, 120, 480, 220);

        return $settings;
    }

    /**
     * Resolve `auto` against the width the dashboard reports for the widget body.
     *
     * Narrow widgets get the dense single-line view, mid widths the widget's natural
     * layout, and wide widgets cards.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function resolveLayout(array $settings, string $slug, Request $request): string
    {
        $layout = (string) ($settings['layout'] ?? 'auto');

        if ($layout !== 'auto') {
            return $layout;
        }

        $layouts = self::layoutsFor($slug);
        $width = (int) ($request->input('dimensions.x') ?? 0);

        if ($width > 0 && $width < 380 && in_array('compact', $layouts, true)) {
            return 'compact';
        }

        if ($width > 900 && in_array('cards', $layouts, true)) {
            return 'cards';
        }

        return self::defaultLayout($slug);
    }

    /**
     * Root element classes for a widget body.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function cssClasses(array $settings, string $slug, string $layout): string
    {
        $classes = [
            'nmsdw-widget',
            'nmsdw-w-' . $slug,
            'nmsdw-layout-' . $layout,
            'nmsdw-accent-' . ($settings['accent'] ?? 'default'),
        ];

        if (($settings['density'] ?? 'comfortable') === 'compact') {
            $classes[] = 'nmsdw-compact';
        }

        if (! empty($settings['zebra'])) {
            $classes[] = 'nmsdw-zebra';
        }

        return implode(' ', $classes);
    }
}
