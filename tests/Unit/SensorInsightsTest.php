<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\SensorInsights;
use Drakelid\NmsDashWidgets\Support\Freshness;
use PHPUnit\Framework\TestCase;

class SensorInsightsTest extends TestCase
{
    public function test_unchanged_reading_uses_recent_device_poll_for_freshness(): void
    {
        $sensor = (object) ['lastupdate' => 1000, 'device' => (object) ['last_polled' => 1990]];
        $age = Freshness::describe(SensorInsights::polledAt($sensor), 900, 2000);
        $this->assertSame(10, $age['seconds']);
        $this->assertFalse($age['stale']);
    }

    public function test_unknown_poll_time_does_not_fall_back_to_value_change(): void
    {
        $sensor = (object) ['lastupdate' => 1990, 'device' => (object) ['last_polled' => null]];
        $age = Freshness::describe(SensorInsights::polledAt($sensor), 900, 2000);
        $this->assertNull($age['seconds']);
        $this->assertTrue($age['stale']);
    }

    public function test_legacy_raw_temperature_history_uses_same_scale_as_current(): void
    {
        $trend = SensorInsights::temperatureTrend([
            'available' => true, 'delta' => 20,
            'points' => [['time' => 1000, 'value' => 350], ['time' => 2000, 'value' => 370]],
        ], 0.1);
        $this->assertSame(2.0, $trend['delta']);
        $this->assertSame(35.0, $trend['points'][0]['value']);
        $this->assertSame(37.0, $trend['points'][1]['value']);
    }

    private function reading(string $direction, string $description, int $portId = 10, string $status = 'ok'): array
    {
        return [
            'sensor' => (object) ['sensor_descr' => $description],
            'port' => (object) ['device_id' => 1, 'port_id' => $portId],
            'direction' => $direction, 'status' => $status,
        ];
    }

    public function test_pairs_only_matching_explicit_lanes(): void
    {
        $rows = SensorInsights::pairOptical([
            $this->reading('rx', 'Receive lane 1'), $this->reading('tx', 'Transmit lane 2'),
            $this->reading('tx', 'Transmit lane 1'), $this->reading('rx', 'Receive lane 2'),
        ]);
        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['lane']);
        $this->assertSame('Transmit lane 1', $rows[0]['readings'][1]['sensor']->sensor_descr);
        $this->assertSame('2', $rows[1]['lane']);
    }

    public function test_run_together_lane_numbers_are_not_merged(): void
    {
        $rows = SensorInsights::pairOptical([$this->reading('rx', 'RxPower1'), $this->reading('tx', 'TxPower2')]);
        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['lane']);
        $this->assertSame('2', $rows[1]['lane']);
    }

    public function test_duplicate_unlabelled_directions_remain_separate(): void
    {
        $rows = SensorInsights::pairOptical([
            $this->reading('rx', 'Receive'), $this->reading('rx', 'Receive power'), $this->reading('tx', 'Transmit'),
        ]);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertCount(1, $row['readings']);
        }
    }

    public function test_unique_pair_keeps_worst_status(): void
    {
        $rows = SensorInsights::pairOptical([$this->reading('rx', 'Receive'), $this->reading('tx', 'Transmit', 10, 'critical')]);
        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]['readings']);
        $this->assertSame('critical', $rows[0]['status']);
    }

    public function test_different_ports_never_pair(): void
    {
        $this->assertCount(2, SensorInsights::pairOptical([
            $this->reading('rx', 'Receive lane 1', 10), $this->reading('tx', 'Transmit lane 1', 11),
        ]));
    }

    public function test_own_temperature_limits_take_precedence_and_missing_values_fall_back(): void
    {
        $limits = SensorInsights::temperatureLimits((object) ['sensor_limit' => 60, 'sensor_limit_warn' => 55], 70, 90);
        $this->assertSame(60.0, $limits['limit']);
        $this->assertSame(55.0, $limits['warn']);
        $this->assertSame('sensor', $limits['limit_source']);
        $fallback = SensorInsights::temperatureLimits((object) [], 70, 90);
        $this->assertSame(90.0, $fallback['limit']);
        $this->assertSame(70.0, $fallback['warn']);
        $this->assertSame('widget', $fallback['limit_source']);
    }

    public function test_raw_limit_normalization_does_not_change_current_or_normalized_limit(): void
    {
        $sensor = (object) ['sensor_current' => 20.9, 'sensor_limit' => 229, 'sensor_divisor' => 10];
        $limits = SensorInsights::temperatureLimits($sensor, 70, 90);
        $this->assertEqualsWithDelta(22.9, $limits['limit'], 0.0001);
        $this->assertSame(20.9, $sensor->sensor_current);
        $sensor->sensor_limit = 90;
        $this->assertSame(90.0, SensorInsights::temperatureLimits($sensor, 70, 90)['limit']);
    }
}
