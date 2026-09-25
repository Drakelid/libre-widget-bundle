<?php

namespace Drakelid\NmsDashWidgets\Support;

use Carbon\Carbon;
use DateTimeInterface;

final class Freshness
{
    /** The observation time, never the time the widget happened to refresh. */
    public static function describe(mixed $timestamp, int $staleAfter = 900, ?int $now = null): array
    {
        try {
            if ($timestamp === null || $timestamp === '' || $timestamp === 0 || $timestamp === '0'
                || str_starts_with((string) ($timestamp instanceof DateTimeInterface ? $timestamp->format('Y') : $timestamp), '0000-')) {
                return ['label' => 'Data age unknown', 'at' => null, 'stale' => true, 'seconds' => null];
            }
            $at = $timestamp instanceof DateTimeInterface ? $timestamp->getTimestamp()
                : (is_numeric($timestamp) ? (int) $timestamp : Carbon::parse($timestamp)->timestamp);
            $now ??= time();
            if ($at > $now + 60 || $at <= 0) {
                return ['label' => 'Observation time invalid', 'at' => null, 'stale' => true, 'seconds' => null];
            }
            $seconds = max(0, $now - $at);
            $age = $seconds < 60 ? $seconds . 's' : ($seconds < 3600 ? (int) floor($seconds / 60) . 'm' : round($seconds / 3600, 1) . 'h');

            return ['label' => 'Observed ' . $age . ' ago', 'at' => gmdate('c', $at), 'stale' => $seconds > max(60, $staleAfter), 'seconds' => $seconds];
        } catch (\Throwable) {
            return ['label' => 'Data age unknown', 'at' => null, 'stale' => true, 'seconds' => null];
        }
    }
}
