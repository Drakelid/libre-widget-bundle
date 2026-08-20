<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Shared value formatting.
 *
 * Behaviour here is deliberately identical to the formatBits()/temperature
 * helpers that were duplicated across the original hardcoded widgets, so that
 * ported widgets render byte-identically to what users see today.
 */
final class Format
{
    /** SI (1000-based) units, matching the original widgets. */
    private const BIT_UNITS = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps', 'Pbps'];

    /**
     * Format a bit rate.
     *
     * Note the decimal rule: raw bps is shown with no decimals, every larger unit
     * with exactly two ("6.10 Gbps", not "6.1 Gbps"). The trailing zero is intentional.
     */
    public static function bits(?float $bits): string
    {
        $bits = (float) ($bits ?? 0.0);

        if (! is_finite($bits)) {
            return '0 ' . self::BIT_UNITS[0];
        }

        $negative = $bits < 0;
        $bits = abs($bits);

        $index = 0;
        $last = count(self::BIT_UNITS) - 1;

        while ($bits >= 1000 && $index < $last) {
            $bits /= 1000;
            $index++;
        }

        $formatted = number_format($bits, $index === 0 ? 0 : 2);

        return ($negative ? '-' : '') . $formatted . ' ' . self::BIT_UNITS[$index];
    }

    /**
     * Format a percentage to one decimal place. Null renders as "n/a".
     */
    public static function percent(?float $value, string $empty = 'n/a'): string
    {
        if ($value === null || ! is_finite($value)) {
            return $empty;
        }

        return number_format($value, 1) . '%';
    }

    /**
     * Format a temperature in Celsius.
     *
     * Trailing zeros are trimmed, so 37.0 renders as "37 °C" and 36.5 as "36.5 °C".
     */
    public static function temperature(?float $value, string $empty = '-'): string
    {
        if ($value === null || ! is_finite($value)) {
            return $empty;
        }

        $formatted = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        if ($formatted === '' || $formatted === '-') {
            $formatted = '0';
        }

        return $formatted . ' °C';
    }

    /**
     * Convert an octets-per-second rate (how LibreNMS stores port rates) to bits per second.
     */
    public static function octetsToBits(mixed $octetRate): float
    {
        return ((float) ($octetRate ?? 0)) * 8;
    }

    /**
     * Utilisation as a percentage of link speed, or null when the speed is unknown.
     *
     * Capped at 100 to match the original widgets. A port reporting more than its
     * ifSpeed almost always means a stale or wrong ifSpeed rather than real traffic.
     */
    public static function utilisation(float $bps, ?float $speedBps): ?float
    {
        if ($speedBps === null || $speedBps <= 0) {
            return null;
        }

        return min(100.0, ($bps / $speedBps) * 100);
    }
}
