<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Temperature sensor scaling and status.
 *
 * PORTED VERBATIM from TopDeviceTemperaturesController. The scaling heuristics below
 * are load bearing correctness logic: they are what stops a rectifier reporting 370
 * deci-Celsius from being displayed as "370 °C". Do not "simplify" them without a
 * dataset to test against -- the thresholds encode real vendor behaviour.
 *
 * The maths takes plain floats rather than Sensor models so it can be unit tested
 * without a database. See sensorScaleFactor() for the Eloquent-facing adapter.
 */
final class Temperature
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Work out the multiplier needed to turn a raw sensor reading into Celsius.
     *
     * @param  array<int, float|null>  $samples  current, limit and warn limit readings
     */
    public static function scaleFactor(array $samples, mixed $divisor = 1, mixed $multiplier = 1): float
    {
        if (self::hasRawDeciCelsiusValues($samples)) {
            return 0.1;
        }

        $divisor = is_numeric($divisor) && (float) $divisor != 0.0 ? (float) $divisor : 1.0;
        $multiplier = is_numeric($multiplier) && (float) $multiplier != 0.0 ? (float) $multiplier : 1.0;

        $factor = $multiplier / $divisor;

        if (abs($factor - 1.0) > 0.0001 && self::scaleWouldCreateSaneTemperature($samples, $factor)) {
            return $factor;
        }

        return 1.0;
    }

    /**
     * Detect readings stored in tenths of a degree.
     *
     * A value of 200 or more that lands in a plausible Celsius range once divided by
     * ten is treated as deci-Celsius.
     *
     * @param  array<int, float|null>  $samples
     */
    public static function hasRawDeciCelsiusValues(array $samples): bool
    {
        foreach ($samples as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $raw = (float) $value;
            $scaled = $raw / 10;

            if ($raw >= 200.0 && $scaled > -80.0 && $scaled <= 130.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanity check a candidate multiplier/divisor factor.
     *
     * Rejects factors that shrink an already-plausible reading, or that push any
     * reading above 180 degrees.
     *
     * @param  array<int, float|null>  $samples
     */
    public static function scaleWouldCreateSaneTemperature(array $samples, float $factor): bool
    {
        foreach ($samples as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $raw = (float) $value;
            $scaled = $raw * $factor;

            if ($raw < 200.0 && $scaled < $raw) {
                return false;
            }

            if ($scaled > 180.0) {
                return false;
            }
        }

        return true;
    }

    /** Apply a scale factor to a single reading. */
    public static function value(mixed $raw, float $scaleFactor): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return ((float) $raw) * $scaleFactor;
    }

    /**
     * Status against the widget's own warn/limit thresholds.
     *
     * Note these are the widget settings, not the sensor's stored limits; the sensor
     * limits are shown in the meter caption but do not drive the status.
     */
    public static function status(?float $current, ?float $warn, ?float $limit): string
    {
        if ($current === null) {
            return self::STATUS_UNKNOWN;
        }

        if ($limit !== null && $current >= $limit) {
            return self::STATUS_CRITICAL;
        }

        if ($warn !== null && $current >= $warn) {
            return self::STATUS_WARNING;
        }

        return self::STATUS_OK;
    }

    /**
     * Meter fill percentage, scaled against the limit when there is one and against
     * the hottest displayed reading otherwise. Floored at 3 so a bar is always visible.
     */
    public static function barPercent(?float $current, ?float $limit, float $maxShown): int
    {
        if ($current === null) {
            return 0;
        }

        $reference = ($limit !== null && $limit > 0) ? $limit : $maxShown;

        if ($reference <= 0) {
            return 0;
        }

        return (int) max(3, min(100, round(($current / $reference) * 100)));
    }

    /**
     * Eloquent-facing adapter: derive the scale factor for a Sensor model.
     *
     * @param  object  $sensor  a LibreNMS App\Models\Sensor
     */
    public static function sensorScaleFactor(object $sensor): float
    {
        return self::scaleFactor(
            [
                $sensor->sensor_current ?? null,
                $sensor->sensor_limit ?? null,
                $sensor->sensor_limit_warn ?? null,
            ],
            $sensor->sensor_divisor ?? 1,
            $sensor->sensor_multiplier ?? 1,
        );
    }
}
