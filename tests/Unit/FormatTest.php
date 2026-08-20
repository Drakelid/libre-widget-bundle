<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    /**
     * The decimal rule is unusual and deliberate: no decimals for raw bps, exactly two
     * for every larger unit. "6.10 Gbps" keeps its trailing zero.
     */
    public function test_bits_uses_si_units_and_the_original_decimal_rule(): void
    {
        $this->assertSame('0 bps', Format::bits(0));
        $this->assertSame('999 bps', Format::bits(999));
        $this->assertSame('1.00 Kbps', Format::bits(1000));
        $this->assertSame('142.61 Mbps', Format::bits(142_610_000));
        $this->assertSame('6.10 Gbps', Format::bits(6_100_000_000));
        $this->assertSame('52.43 Gbps', Format::bits(52_430_000_000));
        $this->assertSame('1.00 Pbps', Format::bits(1e15));
    }

    public function test_bits_does_not_run_past_the_largest_unit(): void
    {
        $this->assertStringEndsWith('Pbps', Format::bits(1e21));
    }

    public function test_bits_handles_null_and_non_finite_input(): void
    {
        $this->assertSame('0 bps', Format::bits(null));
        $this->assertSame('0 bps', Format::bits(INF));
        $this->assertSame('0 bps', Format::bits(NAN));
    }

    public function test_percent_formats_to_one_decimal(): void
    {
        $this->assertSame('14.3%', Format::percent(14.28));
        $this->assertSame('0.7%', Format::percent(0.65));
        $this->assertSame('8.0%', Format::percent(8.0));
        $this->assertSame('n/a', Format::percent(null));
    }

    public function test_temperature_trims_trailing_zeros(): void
    {
        $this->assertSame('37 °C', Format::temperature(37.0));
        $this->assertSame('36.5 °C', Format::temperature(36.5));
        $this->assertSame('-', Format::temperature(null));
        $this->assertSame('n/a', Format::temperature(null, 'n/a'));
    }

    public function test_octets_are_converted_to_bits(): void
    {
        $this->assertSame(8.0, Format::octetsToBits(1));
        $this->assertSame(0.0, Format::octetsToBits(null));
    }

    public function test_utilisation_is_null_without_a_known_speed(): void
    {
        $this->assertNull(Format::utilisation(1000, 0));
        $this->assertNull(Format::utilisation(1000, null));
    }

    public function test_utilisation_is_capped_at_one_hundred(): void
    {
        // A port reporting more than its ifSpeed means a bad ifSpeed, not real traffic.
        $this->assertSame(100.0, Format::utilisation(2_000_000_000, 1_000_000_000));
    }

    public function test_utilisation_matches_the_reference_screenshot(): void
    {
        // TX 142.61 Mbps peak on a 1.00 Gbps link reads 14.3%.
        $this->assertSame('14.3%', Format::percent(Format::utilisation(142_610_000, 1_000_000_000)));
    }
}
