<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\Optical;
use PHPUnit\Framework\TestCase;

/**
 * Descriptions are taken from LibreNMS's own dBm discovery (YAML definitions and
 * includes/discovery/sensors/dbm), so these cover what devices actually report.
 */
class OpticalTest extends TestCase
{
    private const NO_LIMITS = ['low' => null, 'low_warn' => null, 'high_warn' => null, 'high' => null];

    public function test_direction_reads_spelled_out_vendor_wording(): void
    {
        $this->assertSame('rx', Optical::direction('Gi2/12 Receive Power'));
        $this->assertSame('tx', Optical::direction('Gi2/12 Transmit Power'));
        $this->assertSame('rx', Optical::direction('xe-0/0/0 Rx Power'));
        $this->assertSame('tx', Optical::direction('eth1 xcvr TX power'));
        $this->assertSame('rx', Optical::direction('Input Power dBm'));
        $this->assertSame('tx', Optical::direction('Output Power dBm'));
    }

    public function test_direction_reads_run_together_vendor_wording(): void
    {
        // FS
        $this->assertSame('rx', Optical::direction('Ethernet1/1 RxPower'));
        $this->assertSame('tx', Optical::direction('Ethernet1/1 TxPower'));
        // BDCOM
        $this->assertSame('rx', Optical::direction('gpon0/1 rxPower1'));
        $this->assertSame('tx', Optical::direction('gpon0/1 txPower4'));
        // Eltex
        $this->assertSame('rx', Optical::direction('SfpRxDbm-gi1/0/1'));
        $this->assertSame('tx', Optical::direction('SfpTxdBm-gi1/0/1'));
        // All caps, where camelCase splitting cannot help.
        $this->assertSame('tx', Optical::direction('PORT 1 TXPOWER'));
    }

    public function test_direction_ignores_prose_and_lookalikes(): void
    {
        // "in" is prose here; this used to be labelled RX.
        $this->assertSame('tx', Optical::direction('Transmit power in dBm'));
        $this->assertNull(Optical::direction('Proxy port power'));
        $this->assertNull(Optical::direction('Lane 2 power'));
    }

    public function test_direction_falls_back_to_the_sensor_type(): void
    {
        $this->assertSame('rx', Optical::direction('Port 3', 'rxPower'));
    }

    public function test_an_optic_with_no_thresholds_gets_the_widgets_values(): void
    {
        [$limits, $custom] = Optical::mergeLimits(
            self::NO_LIMITS,
            ['low' => -20.0, 'low_warn' => null, 'high_warn' => null, 'high' => null],
            Optical::PRIORITY_OPTIC
        );

        $this->assertSame(-20.0, $limits['low']);
        $this->assertTrue($custom['low']);
        $this->assertFalse($custom['high']);
    }

    public function test_optic_priority_keeps_the_optics_values_and_fills_gaps(): void
    {
        [$limits, $custom] = Optical::mergeLimits(
            ['low' => -23.5, 'low_warn' => null, 'high_warn' => null, 'high' => 0.9],
            ['low' => -20.0, 'low_warn' => -18.0, 'high_warn' => null, 'high' => -1.0],
            Optical::PRIORITY_OPTIC
        );

        $this->assertSame(['low' => -23.5, 'low_warn' => -18.0, 'high_warn' => null, 'high' => 0.9], $limits);
        $this->assertSame(['low' => false, 'low_warn' => true, 'high_warn' => false, 'high' => false], $custom);
    }

    public function test_custom_priority_overrides_only_the_values_that_are_set(): void
    {
        [$limits, $custom] = Optical::mergeLimits(
            ['low' => -23.5, 'low_warn' => -21.0, 'high_warn' => null, 'high' => 0.9],
            ['low' => -25.0, 'low_warn' => null, 'high_warn' => null, 'high' => null],
            Optical::PRIORITY_CUSTOM
        );

        $this->assertSame(['low' => -25.0, 'low_warn' => -21.0, 'high_warn' => null, 'high' => 0.9], $limits);
        $this->assertSame(['low' => true, 'low_warn' => false, 'high_warn' => false, 'high' => false], $custom);
    }

    public function test_status_alarm_limits_are_critical_inclusively(): void
    {
        $limits = ['low' => -20.0, 'low_warn' => -18.0, 'high_warn' => -1.0, 'high' => 0.0];

        $this->assertSame('critical', Optical::status(-20.0, $limits, 3));
        $this->assertSame('critical', Optical::status(0.0, $limits, 3));
        $this->assertSame('warning', Optical::status(-18.0, $limits, 3));
        $this->assertSame('warning', Optical::status(-1.0, $limits, 3));
        $this->assertSame('ok', Optical::status(-10.0, $limits, 3));
    }

    public function test_warn_margin_applies_only_without_a_low_warning_threshold(): void
    {
        $noWarn = ['low' => -20.0, 'low_warn' => null, 'high_warn' => null, 'high' => null];
        $this->assertSame('warning', Optical::status(-17.0, $noWarn, 3));
        $this->assertSame('ok', Optical::status(-16.9, $noWarn, 3));

        $withWarn = ['low' => -20.0, 'low_warn' => -19.0, 'high_warn' => null, 'high' => null];
        $this->assertSame('ok', Optical::status(-17.0, $withWarn, 3));
    }

    public function test_status_is_unknown_without_any_alarm_limit(): void
    {
        $this->assertSame('unknown', Optical::status(-5.0, self::NO_LIMITS, 3));
    }
}
