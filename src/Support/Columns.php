<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Per-widget column visibility.
 *
 * Table widgets let the user hide columns they do not care about -- the device group
 * column on a single-group dashboard, the sparkline on a narrow widget, the last-error
 * text on a wall display, and so on.
 *
 * Some columns are marked required: hiding the device or the thing being measured would
 * leave rows that say nothing, so those are always rendered and shown as fixed in the
 * settings form.
 *
 * Stored as `columns`, a list of visible column keys. An unset value means "the
 * defaults", so existing widgets are unaffected until someone edits them.
 */
final class Columns
{
    /**
     * key => [label, default visible, required]
     *
     * Order here is the order the checkboxes appear in the settings form.
     *
     * @var array<string, array<string, array{0: string, 1: bool, 2: bool}>>
     */
    private const DEFINITIONS = [
        'top-bandwidth-device-group' => [
            'device' => ['Device', true, true],
            'interface' => ['Interface', true, true],
            'usage' => ['Usage', true, true],
            'inout' => ['In / Out', true, false],
            'utilisation' => ['Utilisation', true, false],
            'graph' => ['Graph', true, false],
            'group' => ['Device group', true, false],
        ],
        'uplink-utilization-overview' => [
            'device' => ['Device', true, true],
            'interface' => ['Uplink interface', true, true],
            'utilisation' => ['Utilisation', true, true],
            'traffic' => ['Traffic', true, false],
            'speed' => ['Speed', true, false],
            'graph' => ['Graph', true, false],
            'group' => ['Device group', false, false],
        ],
        'optical-light-levels' => [
            'device' => ['Device', true, true],
            'interface' => ['Interface', true, true],
            'level' => ['Level', true, true],
            'margin' => ['Margin', true, false],
            'thresholds' => ['Thresholds', true, false],
            'optic' => ['Transceiver', true, false],
        ],
        'bgp-session-health' => [
            'device' => ['Device', true, true],
            'peer' => ['Peer', true, true],
            'state' => ['State', true, true],
            'uptime' => ['Uptime', true, false],
            'prefixes' => ['Prefixes', true, false],
            'error' => ['Last error', true, false],
        ],
        'customer-port-status' => [
            'device' => ['Device', true, true],
            'port' => ['Port', true, true],
            'state' => ['State', true, true],
            'downfor' => ['Down for', true, false],
            'group' => ['Device group', true, false],
        ],
        'poller-health' => [
            'device' => ['Device', true, true],
            'lastpolled' => ['Last polled', true, true],
            'status' => ['Device state', true, false],
        ],
        'recently-added-devices' => [
            'device' => ['Device', true, true],
            'hardware' => ['Hardware', true, false],
            'os' => ['OS', true, false],
            'added' => ['Added', true, true],
        ],
        'flapping-devices' => [
            'device' => ['Device', true, true],
            'item' => ['Item', true, true],
            'changes' => ['Changes', true, true],
            'state' => ['State', true, false],
            'last' => ['Last change', true, false],
            'message' => ['Message', true, false],
        ],
    ];

    /**
     * Legacy per-column booleans, kept working.
     *
     * These shipped before the column system existed and are present in saved widget
     * settings. When `columns` has never been set, they seed the initial visibility.
     *
     * @var array<string, array<string, string>>  widget => [column => legacy setting]
     */
    private const LEGACY = [
        'top-bandwidth-device-group' => [
            'graph' => 'show_graphs',
            'utilisation' => 'show_utilisation',
        ],
        'uplink-utilization-overview' => [
            'graph' => 'show_graphs',
            'group' => 'show_device_group',
        ],
        'optical-light-levels' => [
            'optic' => 'show_transceiver_details',
        ],
        'bgp-session-health' => [
            'prefixes' => 'show_prefixes',
        ],
    ];

    public static function supports(string $slug): bool
    {
        return isset(self::DEFINITIONS[$slug]);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function definitionsFor(string $slug): array
    {
        return self::DEFINITIONS[$slug] ?? [];
    }

    /**
     * Column keys visible by default.
     *
     * @return list<string>
     */
    public static function defaults(string $slug): array
    {
        $keys = [];

        foreach (self::definitionsFor($slug) as $key => [$label, $default, $required]) {
            if ($default || $required) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Resolve the stored setting to a key => bool map for the blade.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, bool>
     */
    public static function visible(array $settings, string $slug): array
    {
        $definitions = self::definitionsFor($slug);

        if ($definitions === []) {
            return [];
        }

        $stored = $settings['columns'] ?? null;

        if ($stored === null || $stored === '' || $stored === []) {
            // Never configured: fall back to the legacy toggles, then the defaults.
            $selected = self::seedFromLegacy($settings, $slug);
        } else {
            $selected = is_array($stored) ? $stored : explode(',', (string) $stored);
            $selected = array_values(array_filter(array_map(
                fn ($k): string => trim((string) $k),
                $selected
            )));
        }

        $visible = [];

        foreach ($definitions as $key => [$label, $default, $required]) {
            $visible[$key] = $required || in_array($key, $selected, true);
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private static function seedFromLegacy(array $settings, string $slug): array
    {
        $keys = self::defaults($slug);
        $legacy = self::LEGACY[$slug] ?? [];

        foreach ($legacy as $column => $settingKey) {
            if (! array_key_exists($settingKey, $settings)) {
                continue;
            }

            // The legacy toggle wins while `columns` is unset, so a widget that had its
            // graphs turned off keeps them off.
            if (! Cast::bool($settings[$settingKey], true)) {
                $keys = array_values(array_filter($keys, fn (string $k): bool => $k !== $column));
            } elseif (! in_array($column, $keys, true)) {
                $keys[] = $column;
            }
        }

        return $keys;
    }

    /**
     * Coerce the stored `columns` value.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings, string $slug): array
    {
        if (! self::supports($slug)) {
            return $settings;
        }

        $definitions = self::definitionsFor($slug);
        $stored = $settings['columns'] ?? null;

        if ($stored === null) {
            return $settings; // untouched: visible() will seed from legacy toggles
        }

        $selected = is_array($stored) ? $stored : explode(',', (string) $stored);

        $settings['columns'] = array_values(array_filter(
            array_map(fn ($k): string => trim((string) $k), $selected),
            fn (string $k): bool => isset($definitions[$k])
        ));

        return $settings;
    }
}
