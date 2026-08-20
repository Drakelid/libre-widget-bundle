<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Coercion helpers for widget settings.
 *
 * Widget settings are user-supplied JSON stored in users_widgets.settings. Core
 * validates only that the blob is an array and that `refresh` is a positive int,
 * so everything else must be treated as untrusted on read. Values are commonly
 * strings even when they represent numbers or booleans ("60", "1", "0").
 *
 * Every helper is total: it returns the fallback rather than throwing.
 */
final class Cast
{
    /** Integer clamped to a range, falling back when absent or out of range. */
    public static function int(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    /**
     * Integer squeezed into a range.
     *
     * Unlike int(), an out-of-range value is pulled to the nearest bound instead of
     * being discarded. Use this where the original widgets used max(1, min(50, ...)).
     */
    public static function clampedInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    /** Float squeezed into a range. */
    public static function clampedFloat(mixed $value, float $min, float $max, float $fallback): float
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (float) $value));
    }

    /** Float with no range constraint. */
    public static function float(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * Boolean from the many shapes settings arrive in: true, "1", 1, "yes", "on",
     * and their negatives. Anything unrecognised yields the fallback.
     */
    public static function bool(mixed $value, bool $fallback): bool
    {
        if ($value === null) {
            return $fallback;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $fallback;
    }

    /** One of a fixed set of string choices. */
    public static function choice(mixed $value, array $allowed, string $fallback): string
    {
        $value = trim((string) ($value ?? ''));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** A #rgb or #rrggbb colour, falling back when malformed. */
    public static function color(mixed $value, string $fallback): string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) === 1
            ? $value
            : $fallback;
    }

    /** Trimmed string, or null when empty. */
    public static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
