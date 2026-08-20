<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\SafeRegex;
use PHPUnit\Framework\TestCase;

class SafeRegexTest extends TestCase
{
    public function test_an_empty_pattern_is_empty_not_invalid(): void
    {
        $regex = SafeRegex::make('');

        $this->assertTrue($regex->isEmpty());
        $this->assertFalse($regex->isInvalid());
        $this->assertFalse($regex->isUsable());
        $this->assertNull($regex->errorCode());
    }

    public function test_null_is_treated_as_empty(): void
    {
        $this->assertTrue(SafeRegex::make(null)->isEmpty());
    }

    public function test_a_bare_alternation_matches_case_insensitively(): void
    {
        $regex = SafeRegex::make('uplink|upstream|trunk|wan|core|backbone|transport');

        $this->assertTrue($regex->isUsable());
        $this->assertTrue($regex->matches('Bundle to anb-stv-gm1agg1'.' TRUNK'));
        $this->assertTrue($regex->matches('## Transportlink to hk-lonningsasen-pe2 ##'));
        $this->assertFalse($regex->matches('Gi0/0/1 customer access'));
    }

    public function test_forward_slashes_do_not_break_the_delimiter(): void
    {
        $regex = SafeRegex::make('gi\d+/\d+');

        $this->assertTrue($regex->isUsable());
        $this->assertTrue($regex->matches('Gi0/1 uplink'));
    }

    public function test_an_invalid_pattern_is_reported_not_thrown(): void
    {
        $regex = SafeRegex::make('uplink(');

        $this->assertTrue($regex->isInvalid());
        $this->assertFalse($regex->isUsable());
        $this->assertSame(SafeRegex::ERROR_INVALID, $regex->errorCode());
        $this->assertFalse($regex->matches('anything'));
    }

    public function test_an_over_long_pattern_is_rejected(): void
    {
        $regex = SafeRegex::make(str_repeat('a', SafeRegex::MAX_LENGTH + 1));

        $this->assertTrue($regex->isInvalid());
        $this->assertSame(SafeRegex::ERROR_TOO_LONG, $regex->errorCode());
    }

    public function test_a_pattern_at_the_length_limit_is_accepted(): void
    {
        $this->assertTrue(SafeRegex::make(str_repeat('a', SafeRegex::MAX_LENGTH))->isUsable());
    }

    /**
     * The live install stores "outlet|inlet|" -- a trailing alternation, which matches
     * the empty string and therefore everything. Users should be told.
     */
    public function test_a_trailing_pipe_is_detected_as_matching_everything(): void
    {
        $regex = SafeRegex::make('outlet|inlet|');

        $this->assertTrue($regex->isUsable());
        $this->assertTrue($regex->matchesEmptyString());
        $this->assertTrue($regex->matches('anything at all'));
    }

    public function test_a_normal_pattern_does_not_match_the_empty_string(): void
    {
        $this->assertFalse(SafeRegex::make('outlet|inlet')->matchesEmptyString());
    }

    /**
     * Catastrophic backtracking must degrade the pattern rather than stalling the
     * widget on every remaining row.
     */
    public function test_catastrophic_backtracking_degrades_the_pattern(): void
    {
        $regex = SafeRegex::make('(a+)+$');
        $this->assertTrue($regex->isUsable());

        $subject = str_repeat('a', 60) . 'X';
        $this->assertFalse($regex->matches($subject));

        $this->assertTrue($regex->isDegraded());
        $this->assertFalse($regex->isUsable());
        $this->assertSame(SafeRegex::ERROR_DEGRADED, $regex->errorCode());
    }

    public function test_whitespace_around_a_pattern_is_trimmed(): void
    {
        $this->assertSame('uplink', SafeRegex::make('  uplink  ')->raw());
    }
}
