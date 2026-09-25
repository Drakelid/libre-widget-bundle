<?php

namespace Drakelid\NmsDashWidgets\Support;

final class SensorInsights
{
    /** lastupdate marks a value change, not a poll; device polling is the available freshness proxy. */
    public static function polledAt(object $sensor): mixed
    {
        return ($sensor->device ?? null)?->last_polled ?? null;
    }

    public static function temperatureTrend(array $trend, float $scale): array
    {
        if (! ($trend['available'] ?? false)) {
            return $trend;
        }
        $trend['delta'] *= $scale;
        foreach ($trend['points'] ?? [] as $i => $point) {
            if (isset($point['value'])) {
                $trend['points'][$i]['value'] = $point['value'] * $scale;
            }
        }

        return $trend;
    }

    /** Normalize each threshold independently of the already-polled reading. */
    public static function temperatureLimits(object $sensor, float $fallbackWarn, float $fallbackLimit): array
    {
        $normalize = static function (mixed $value) use ($sensor): ?float {
            if (! is_numeric($value)) {
                return null;
            }

            return (float) $value * Temperature::sensorScaleFactor((object) [
                'sensor_current' => $value,
                'sensor_divisor' => $sensor->sensor_divisor ?? 1,
                'sensor_multiplier' => $sensor->sensor_multiplier ?? 1,
            ]);
        };
        $limit = $normalize($sensor->sensor_limit ?? null);
        $warn = $normalize($sensor->sensor_limit_warn ?? null);

        return [
            'limit' => $limit ?? $fallbackLimit,
            'warn' => $warn ?? min($fallbackWarn, ($limit ?? $fallbackLimit) - 1),
            'limit_source' => $limit === null ? 'widget' : 'sensor',
            'warn_source' => $warn === null ? 'widget' : 'sensor',
        ];
    }

    public static function lane(string $description): ?string
    {
        return preg_match('/\b(?:lane|channel|ch)\s*[-:#]?\s*(\d+)\b|\b(?:rx|tx)(?:[ _-]?(?:power|pwr))?[ _-]*(\d+)\b/i', $description, $match)
            ? (string) (int) ($match[1] !== '' ? $match[1] : $match[2]) : null;
    }

    /** Pair only unique directions within an interface/lane; never guess among duplicates. */
    public static function pairOptical(array $rows): array
    {
        $groups = [];
        foreach ($rows as $i => $row) {
            $port = $row['port'] ?? null;
            $lane = self::lane((string) $row['sensor']->sensor_descr);
            $key = $port ? $port->device_id . ':' . $port->port_id . ':' . ($lane ?? 'unlabelled') : 'sensor:' . $i;
            $groups[$key][] = $i;
        }

        $paired = [];
        $used = [];
        foreach ($rows as $i => $row) {
            if (isset($used[$i])) {
                continue;
            }
            $port = $row['port'] ?? null;
            $lane = self::lane((string) $row['sensor']->sensor_descr);
            $key = $port ? $port->device_id . ':' . $port->port_id . ':' . ($lane ?? 'unlabelled') : 'sensor:' . $i;
            $indices = $groups[$key];
            $readings = [$row];
            if (count($indices) === 2) {
                [$a, $b] = $indices;
                $directions = [$rows[$a]['direction'], $rows[$b]['direction']];
                if (in_array('rx', $directions, true) && in_array('tx', $directions, true)) {
                    $readings = [$rows[$a], $rows[$b]];
                    $used[$a] = $used[$b] = true;
                }
            }
            $row['readings'] = $readings;
            $row['lane'] = $lane;
            $observations = array_map(fn (array $reading) => $reading['observed_at'] ?? null, $readings);
            $row['observed_at'] = in_array(null, $observations, true) ? null : min($observations);
            $weight = ['critical' => 0, 'warning' => 1, 'unknown' => 2, 'ok' => 3];
            foreach ($readings as $reading) {
                if (($weight[$reading['status']] ?? 4) < ($weight[$row['status']] ?? 4)) {
                    $row['status'] = $reading['status'];
                }
            }
            $paired[] = $row;
        }

        return $paired;
    }
}
