<?php

namespace Drakelid\NmsDashWidgets\Support;

use App\Facades\LibrenmsConfig;

/**
 * Which map layers a LibreNMS installation can actually offer.
 *
 * Layer choice is not a property of the widget: it depends entirely on which mapping
 * engine is configured. init_map() in html/js/librenms.js branches on `geoloc.engine`
 * and `geoloc.api_key`, and the final fallback -- the default when no engine is set --
 * adds a single OpenStreetMap tile layer and ignores `config.layer` completely.
 *
 * That is why offering Streets / Satellite / Topography unconditionally is misleading:
 * on a default install every one of them silently does nothing. Core's own World Map
 * settings form has the same problem; this class lets ours only offer what will work.
 *
 * The engine lives at Settings -> External -> Location Settings (/settings/external/location),
 * shown as "Mapping Engine" (geoloc.engine, group external, section location). Its
 * options are Google Maps, OpenStreetMap, MapQuest, Bing Maps and ESRI ArcGIS.
 *
 * Note "OpenStreetMap" is a selectable engine but matches no branch in init_map(), so it
 * lands on the same single-layer fallback as leaving the setting unset.
 */
final class MapLayers
{
    public const STREETS = 'Streets';
    public const SATELLITE = 'Satellite';
    public const TOPOGRAPHY = 'Topography';

    /**
     * Layers available for the given engine, in the order init_map() defines them.
     *
     * An empty list means the installation has a single fixed layer and the setting
     * cannot do anything.
     *
     * @return list<string>
     */
    public static function availableFor(?string $engine, ?string $apiKey): array
    {
        $engine = strtolower(trim((string) $engine));
        $hasKey = trim((string) $apiKey) !== '';

        return match (true) {
            // Esri is the only engine that works without a key: without one it falls
            // back to raster ArcGIS tiles, which still provide all three layers.
            $engine === 'esri' => [self::STREETS, self::TOPOGRAPHY, self::SATELLITE],

            // These need a key. Without one init_map() drops through to the single
            // OpenStreetMap layer, and the setting is ignored.
            in_array($engine, ['google', 'bing', 'mapquest'], true) && $hasKey
                => [self::STREETS, self::SATELLITE],

            // No engine configured, or 'openstreetmap' explicitly selected: init_map()
            // has no branch for either, so both land on the single OpenStreetMap layer.
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        return self::availableFor(
            LibrenmsConfig::get('geoloc.engine'),
            LibrenmsConfig::get('geoloc.api_key')
        );
    }

    public static function engine(): string
    {
        $engine = trim((string) LibrenmsConfig::get('geoloc.engine'));

        return $engine === '' ? 'openstreetmap' : $engine;
    }

    /**
     * Does this installation let the user pick a layer at all?
     */
    public static function selectable(): bool
    {
        return self::available() !== [];
    }
}
