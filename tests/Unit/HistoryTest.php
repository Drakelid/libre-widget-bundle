<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\History;
use PHPUnit\Framework\TestCase;

class HistoryTest extends TestCase
{
    public function test_parser_preserves_unknown_samples_and_directions(): void
    {
        $points = History::parse(" INOCTETS OUTOCTETS\n300: 1.0e+08 -nan\n600: NaN 2.0e+07\n");
        self::assertSame(100000000.0, $points[0]['INOCTETS']);
        self::assertNull($points[0]['OUTOCTETS']);
        self::assertNull($points[1]['INOCTETS']);
        self::assertSame(20000000.0, $points[1]['OUTOCTETS']);
    }

    public function test_congestion_requires_every_interval_and_uses_peak_direction(): void
    {
        $points = array_map(fn ($time) => ['time' => $time, 'INOCTETS' => 100000000, 'OUTOCTETS' => 75000000], [300, 600, 900]);
        $result = History::congestion($points, 1000000000, 80, 0, 900);
        self::assertTrue($result['sustained']);
        self::assertSame(80.0, $result['points'][0]['value']);
        self::assertFalse(History::congestion($points, 1000000000, 90, 0, 900)['sustained']);
        $points[1]['INOCTETS'] = null;
        self::assertFalse(History::congestion($points, 1000000000, 80, 0, 900)['available']);
        unset($points[1]);
        self::assertFalse(History::congestion($points, 1000000000, 80, 0, 900)['available']);
    }

    public function test_trend_preserves_negative_values_and_rejects_incomplete_windows(): void
    {
        $points = array_map(fn ($time) => ['time' => $time, 'sensor' => -5 - $time / 3600], range(0, 3600, 300));
        self::assertSame(-1.0, History::trend($points, 0, 3600)['delta']);
        self::assertFalse(History::trend([$points[0], $points[12]], 0, 3600)['available']);
        self::assertFalse(History::trend(array_slice($points, 5), 0, 3600)['available']);
        foreach (range(3, 8) as $i) {
            $points[$i]['sensor'] = null;
        }
        self::assertFalse(History::trend($points, 0, 3600)['available']);
    }
}
