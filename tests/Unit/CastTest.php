<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\Cast;
use PHPUnit\Framework\TestCase;

/**
 * Widget settings arrive as untrusted JSON, typically with every value stringified.
 * These tests use the shapes actually present in the live users_widgets rows.
 */
class CastTest extends TestCase
{
    public function test_int_rejects_out_of_range_values_and_falls_back(): void
    {
        $this->assertSame(170, Cast::int('170', 120, 320, 170));
        $this->assertSame(170, Cast::int('9999', 120, 320, 170));
        $this->assertSame(170, Cast::int('-5', 120, 320, 170));
        $this->assertSame(170, Cast::int('not a number', 120, 320, 170));
        $this->assertSame(170, Cast::int(null, 120, 320, 170));
        $this->assertSame(200, Cast::int('200', 120, 320, 170));
    }

    public function test_clamped_int_pulls_to_the_nearest_bound(): void
    {
        $this->assertSame(50, Cast::clampedInt('9999', 1, 50, 10));
        $this->assertSame(1, Cast::clampedInt('-5', 1, 50, 10));
        $this->assertSame(10, Cast::clampedInt('abc', 1, 50, 10));
    }

    public function test_clamped_float_handles_thresholds(): void
    {
        $this->assertSame(70.0, Cast::clampedFloat('70', 1, 100, 70));
        $this->assertSame(100.0, Cast::clampedFloat('150', 1, 100, 70));
        $this->assertSame(70.0, Cast::clampedFloat('', 1, 100, 70));
    }

    public function test_bool_understands_stringified_flags(): void
    {
        $this->assertTrue(Cast::bool('1', false));
        $this->assertFalse(Cast::bool('0', true));
        $this->assertTrue(Cast::bool(1, false));
        $this->assertFalse(Cast::bool(0, true));
        $this->assertTrue(Cast::bool(true, false));
        $this->assertTrue(Cast::bool('yes', false));
        $this->assertFalse(Cast::bool('no', true));
    }

    public function test_bool_falls_back_for_null_and_nonsense(): void
    {
        $this->assertTrue(Cast::bool(null, true));
        $this->assertFalse(Cast::bool(null, false));
        $this->assertTrue(Cast::bool('maybe', true));
    }

    public function test_choice_only_accepts_known_values(): void
    {
        $modes = ['auto', 'cards', 'compact', 'list', 'summary'];

        $this->assertSame('list', Cast::choice('list', $modes, 'auto'));
        $this->assertSame('auto', Cast::choice('grid', $modes, 'auto'));
        $this->assertSame('auto', Cast::choice(null, $modes, 'auto'));
    }

    public function test_color_validates_hex_notation(): void
    {
        $this->assertSame('#80ff80', Cast::color('#80ff80', '#d9534f'));
        $this->assertSame('#fff', Cast::color('#fff', '#d9534f'));
        $this->assertSame('#d9534f', Cast::color('red', '#d9534f'));
        $this->assertSame('#d9534f', Cast::color('#gggggg', '#d9534f'));
        $this->assertSame('#d9534f', Cast::color(null, '#d9534f'));
        // No expression injection into the style attribute.
        $this->assertSame('#d9534f', Cast::color('#fff; background: url(x)', '#d9534f'));
    }

    public function test_nullable_string_collapses_blank_values(): void
    {
        $this->assertNull(Cast::nullableString(null));
        $this->assertNull(Cast::nullableString(''));
        $this->assertNull(Cast::nullableString('   '));
        $this->assertSame('UPS Temperature Monitor', Cast::nullableString('  UPS Temperature Monitor  '));
    }
}
