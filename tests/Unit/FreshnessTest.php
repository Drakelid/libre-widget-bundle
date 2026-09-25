<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\Freshness;
use PHPUnit\Framework\TestCase;

class FreshnessTest extends TestCase
{
    public function test_observation_age_does_not_use_widget_refresh_time(): void
    {
        self::assertSame(1000, Freshness::describe(1000, 900, 2000)['seconds']);
        self::assertTrue(Freshness::describe(1000, 900, 2000)['stale']);
        self::assertFalse(Freshness::describe(new \DateTimeImmutable('@1500'), 900, 2000)['stale']);
    }

    public function test_missing_invalid_and_future_observations_are_not_fresh(): void
    {
        foreach ([null, '', 0, '0000-00-00 00:00:00', 'invalid timestamp', 9000] as $timestamp) {
            $age = Freshness::describe($timestamp, 900, 2000);
            self::assertNull($age['seconds']);
            self::assertTrue($age['stale']);
        }
    }
}
