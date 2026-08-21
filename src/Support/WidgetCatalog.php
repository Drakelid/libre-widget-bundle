<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * The widgets this bundle provides, and which of them are switched on.
 *
 * Single source of truth for the route registration, the plugin settings page and the
 * admin listing. Slugs are permanent: they are stored in users_widgets.widget.
 */
final class WidgetCatalog
{
    /** slug => [controller class (short name), title, description] */
    private const WIDGETS = [
        'device-group-down-count' => [
            'DeviceGroupDownCountController',
            'Device Group Down Count',
            'Down device counts per selected device group, with a combined total.',
        ],
        'top-bandwidth-device-group' => [
            'TopBandwidthDeviceGroupController',
            'Top Bandwidth Usage by Device Group',
            'Busiest ports by combined in + out throughput.',
        ],
        'uplink-utilization-overview' => [
            'UplinkUtilizationOverviewController',
            'Uplink Utilization Overview',
            'Regex-matched uplinks ranked by peak utilisation, with summary statistics across every match.',
        ],
        'top-device-temperatures' => [
            'TopDeviceTemperaturesController',
            'Top Device Temperatures',
            'Hottest devices by temperature sensor. One row per device.',
        ],
        'flapping-devices' => [
            'FlappingDevicesController',
            'Flapping Devices / Unstable Links',
            'Devices and ports that changed state repeatedly within a lookback window.',
        ],
        'recently-added-devices' => [
            'RecentlyAddedDevicesController',
            'Recently Added Devices',
            'Most recently added devices, newest first.',
        ],
        'optical-light-levels' => [
            'OpticalLightLevelsController',
            'Optical Light Levels',
            'Transceiver RX/TX levels ranked by margin above the low threshold. Needs optics that report DDM.',
        ],
        'bgp-session-health' => [
            'BgpSessionHealthController',
            'BGP Session Health',
            'Sessions administratively up but not established, recently re-established, or losing prefixes.',
        ],
        'site-power-status' => [
            'SitePowerStatusController',
            'Site Power and Battery',
            'Battery runtime, charge and DC voltage per device or per site.',
        ],
        'customer-port-status' => [
            'CustomerPortStatusController',
            'Customer Ports Down',
            'Customer-facing ports that are administratively up but operationally down.',
        ],
        'offline-devices-map' => [
            'OfflineDevicesMapController',
            'Offline Devices Map',
            'Geographic map of devices, filtered by any number of device groups. The built-in World Map accepts only one.',
        ],
        'poller-health' => [
            'PollerHealthController',
            'Poller Health',
            'Devices whose data has gone stale, and poller nodes that stopped reporting.',
        ],
    ];

    /** Settings key holding the list of enabled widget slugs. */
    public const SETTING = 'enabled_widgets';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function all(): array
    {
        return self::WIDGETS;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::WIDGETS);
    }

    public static function exists(string $slug): bool
    {
        return isset(self::WIDGETS[$slug]);
    }

    public static function controller(string $slug): ?string
    {
        return self::WIDGETS[$slug][0] ?? null;
    }

    public static function title(string $slug): string
    {
        return self::WIDGETS[$slug][1] ?? $slug;
    }

    public static function description(string $slug): string
    {
        return self::WIDGETS[$slug][2] ?? '';
    }

    /**
     * Resolve the stored setting to the list of enabled slugs.
     *
     * An absent setting means everything is on, so installing an update that adds a
     * widget does not silently leave it switched off, and existing installs are
     * unaffected by this feature being introduced.
     *
     * @param  array<string, mixed>  $settings  plugin settings from the plugin manager
     * @return list<string>
     */
    public static function enabled(array $settings): array
    {
        $stored = $settings[self::SETTING] ?? null;

        if ($stored === null) {
            return self::slugs();
        }

        if (is_string($stored)) {
            $stored = $stored === '' ? [] : explode(',', $stored);
        }

        if (! is_array($stored)) {
            return self::slugs();
        }

        return array_values(array_filter(
            array_map(fn ($s): string => trim((string) $s), $stored),
            fn (string $s): bool => self::exists($s)
        ));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function isEnabled(string $slug, array $settings): bool
    {
        return in_array($slug, self::enabled($settings), true);
    }
}
