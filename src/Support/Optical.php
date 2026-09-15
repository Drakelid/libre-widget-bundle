<?php

namespace Drakelid\NmsDashWidgets\Support;

/**
 * Direction, threshold and status rules for the Optical Light Levels widget.
 *
 * Kept free of models so the rules can be unit tested without a database.
 */
final class Optical
{
    /** The four limits a reading can carry, lowest to highest. */
    public const LIMITS = ['low', 'low_warn', 'high_warn', 'high'];

    /** The optic's own thresholds win; the widget's fill in what it does not report. */
    public const PRIORITY_OPTIC = 'optic';

    /** The widget's thresholds win; the optic's fill in whatever is left empty. */
    public const PRIORITY_CUSTOM = 'custom';

    public const PRIORITIES = [self::PRIORITY_OPTIC, self::PRIORITY_CUSTOM];

    /** Accepted range for a threshold set in the widget. Real optics sit well inside it. */
    public const MIN_DBM = -60.0;

    public const MAX_DBM = 30.0;

    /**
     * RX or TX, read from the sensor description and type.
     *
     * Deliberately does NOT match a bare "in" or "out". Those appear in ordinary prose
     * ("power in dBm"), and because receive is tested first a transmit sensor whose
     * description happened to contain the word "in" was being labelled RX -- and then
     * excluded by the tx_only filter. The explicit "input"/"output" alternatives cover
     * the real vendor wording without that risk.
     *
     * Vendors also run the token into its neighbours -- "RxPower" (FS), "rxPower1"
     * (BDCOM), "SfpTxdBm-" (Eltex) -- which a whole-word match misses. CamelCase and
     * letter/digit boundaries are split first, and the all-caps run-together forms
     * ("TXPOWER") are matched explicitly.
     *
     * Anything unrecognised stays null rather than being guessed at; those readings
     * only appear in the combined modes.
     */
    public static function direction(string $descr, string $type = ''): ?string
    {
        $text = self::words($descr . ' ' . $type);

        if (preg_match('/\b(rx|rxd|rcv|rcvd|recv|receive|received|input)\b|\brx(power|pwr|level|lvl|dbm)\b/', $text)) {
            return 'rx';
        }

        if (preg_match('/\b(tx|txd|xmit|transmit|transmitted|output)\b|\btx(power|pwr|level|lvl|dbm)\b/', $text)) {
            return 'tx';
        }

        return null;
    }

    /**
     * Merge the optic's limits with the ones set in the widget, limit by limit.
     *
     * A value left empty in the widget never overrides anything, so under either
     * priority an optic that reports nothing gets the widget's values, and a widget
     * with nothing set leaves the optic's values alone.
     *
     * @param  array<string, ?float>  $optic  keyed by LIMITS
     * @param  array<string, ?float>  $custom  keyed by LIMITS
     * @return array{0: array<string, ?float>, 1: array<string, bool>} the merged limits,
     *                                                                  and which came from the widget
     */
    public static function mergeLimits(array $optic, array $custom, string $priority): array
    {
        $limits = [];
        $fromCustom = [];

        foreach (self::LIMITS as $key) {
            $o = $optic[$key] ?? null;
            $c = $custom[$key] ?? null;
            $useCustom = $c !== null && ($priority === self::PRIORITY_CUSTOM || $o === null);

            $limits[$key] = $useCustom ? $c : $o;
            $fromCustom[$key] = $useCustom;
        }

        return [$limits, $fromCustom];
    }

    /**
     * Critical at or beyond either alarm limit. Overdrive (above the high limit) matters
     * too: it cooks the far-end receiver.
     *
     * Warning uses the low/high warning thresholds -- the optic's own, which DDM parts
     * report a little inside their alarm limits, or the widget's. A long-haul optic and
     * a 10m DAC have very different ideas of what "close to dark" means, so a specific
     * threshold beats one flat figure; the flat warn margin covers only readings that
     * have no low warning threshold at all.
     *
     * @param  array<string, ?float>  $limits  keyed by LIMITS
     */
    public static function status(float $current, array $limits, float $warnMargin): string
    {
        $low = $limits['low'] ?? null;
        $lowWarn = $limits['low_warn'] ?? null;
        $highWarn = $limits['high_warn'] ?? null;
        $high = $limits['high'] ?? null;

        if ($low !== null && $current <= $low) {
            return 'critical';
        }

        if ($high !== null && $current >= $high) {
            return 'critical';
        }

        if ($lowWarn !== null && $current <= $lowWarn) {
            return 'warning';
        }

        if ($highWarn !== null && $current >= $highWarn) {
            return 'warning';
        }

        if ($lowWarn === null && $low !== null && $current <= ($low + $warnMargin)) {
            return 'warning';
        }

        if ($low === null && $high === null) {
            return 'unknown';
        }

        return 'ok';
    }

    /** Lowercase words, with camelCase and letter/digit runs split apart. */
    private static function words(string $text): string
    {
        $split = preg_replace(
            ['/(?<=[a-z])(?=[A-Z])/', '/(?<=[A-Za-z])(?=[0-9])/', '/(?<=[0-9])(?=[A-Za-z])/'],
            ' ',
            $text
        );

        return strtolower(trim($split ?? $text));
    }
}
