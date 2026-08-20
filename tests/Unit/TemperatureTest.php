<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\Temperature;
use PHPUnit\Framework\TestCase;

/**
 * These cover the scaling heuristics ported verbatim from the original widget. They
 * exist so the logic can be refactored safely -- it is what stops a rectifier
 * reporting 370 deci-Celsius from being displayed as "370 °C".
 */
class TemperatureTest extends TestCase
{
    public function test_deci_celsius_readings_are_scaled_down(): void
    {
        // 370 raw -> 37.0 °C
        $this->assertSame(0.1, Temperature::scaleFactor([370.0, 900.0, 700.0]));
    }

    public function test_plausible_celsius_readings_are_left_alone(): void
    {
        $this->assertSame(1.0, Temperature::scaleFactor([37.0, 90.0, 70.0]));
    }

    public function test_deci_celsius_detection_needs_a_plausible_scaled_value(): void
    {
        // 2000 / 10 = 200, above the 130 ceiling, so this is not deci-Celsius.
        $this->assertFalse(Temperature::hasRawDeciCelsiusValues([2000.0]));

        // 199 is below the 200 floor.
        $this->assertFalse(Temperature::hasRawDeciCelsiusValues([199.0]));

        $this->assertTrue(Temperature::hasRawDeciCelsiusValues([200.0]));
    }

    public function test_non_numeric_samples_are_skipped(): void
    {
        $this->assertFalse(Temperature::hasRawDeciCelsiusValues([null, '', 'abc']));
        $this->assertSame(1.0, Temperature::scaleFactor([null, null, null]));
    }

    public function test_the_deci_celsius_check_wins_over_multiplier_and_divisor(): void
    {
        // 500 would also scale by the divisor, but 500/10 = 50 is a plausible
        // temperature, so the deci-Celsius branch takes precedence and returns 0.1.
        $this->assertSame(0.1, Temperature::scaleFactor([500.0], 10, 1));
    }

    public function test_multiplier_is_used_when_the_deci_check_does_not_apply(): void
    {
        // 50 is below the 200 floor, so deci detection does not fire. A multiplier of 2
        // grows the reading to 100, which is still plausible, so it is accepted.
        $this->assertSame(2.0, Temperature::scaleFactor([50.0], 1, 2));
    }

    public function test_a_divisor_cannot_shrink_a_reading_below_the_deci_floor(): void
    {
        // The sanity check rejects any factor that shrinks an already-plausible value,
        // so a divisor only ever applies to readings the deci branch did not claim.
        $this->assertSame(1.0, Temperature::scaleFactor([150.0], 3, 1));
    }

    public function test_a_factor_that_shrinks_a_plausible_reading_is_rejected(): void
    {
        // 40 °C is already plausible; halving it would be wrong.
        $this->assertSame(1.0, Temperature::scaleFactor([40.0], 2, 1));
    }

    public function test_a_factor_producing_an_absurd_temperature_is_rejected(): void
    {
        $this->assertSame(1.0, Temperature::scaleFactor([100.0], 1, 10));
    }

    public function test_zero_divisor_does_not_divide_by_zero(): void
    {
        $this->assertSame(1.0, Temperature::scaleFactor([40.0], 0, 0));
    }

    public function test_value_applies_the_factor(): void
    {
        $this->assertSame(37.0, Temperature::value(370, 0.1));
        $this->assertNull(Temperature::value('not a number', 0.1));
        $this->assertNull(Temperature::value(null, 1.0));
    }

    public function test_status_uses_widget_thresholds_inclusively(): void
    {
        $this->assertSame(Temperature::STATUS_OK, Temperature::status(50.0, 70.0, 90.0));
        $this->assertSame(Temperature::STATUS_WARNING, Temperature::status(70.0, 70.0, 90.0));
        $this->assertSame(Temperature::STATUS_CRITICAL, Temperature::status(90.0, 70.0, 90.0));
        $this->assertSame(Temperature::STATUS_UNKNOWN, Temperature::status(null, 70.0, 90.0));
    }

    public function test_bar_percent_scales_against_the_limit_and_has_a_visible_floor(): void
    {
        $this->assertSame(50, Temperature::barPercent(45.0, 90.0, 45.0));
        $this->assertSame(100, Temperature::barPercent(200.0, 90.0, 200.0));
        // Floored at 3 so a bar is always visible.
        $this->assertSame(3, Temperature::barPercent(0.5, 90.0, 90.0));
        $this->assertSame(0, Temperature::barPercent(null, 90.0, 90.0));
    }

    public function test_bar_percent_falls_back_to_the_hottest_shown_value(): void
    {
        $this->assertSame(50, Temperature::barPercent(20.0, null, 40.0));
        $this->assertSame(0, Temperature::barPercent(20.0, null, 0.0));
    }
}
