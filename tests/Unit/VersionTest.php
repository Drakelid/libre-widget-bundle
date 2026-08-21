<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\Version;
use PHPUnit\Framework\TestCase;

/**
 * These exist because this formatting shipped broken once: the template added a "v"
 * to a version that already carried one from the git tag, producing "vv1.1.2".
 */
class VersionTest extends TestCase
{
    public function test_a_tag_that_already_has_a_v_does_not_gain_a_second(): void
    {
        $this->assertSame('v1.1.2', Version::format('v1.1.2'));
    }

    public function test_a_tag_without_a_v_gains_one(): void
    {
        $this->assertSame('v1.1.2', Version::format('1.1.2'));
    }

    public function test_an_uppercase_prefix_is_normalised(): void
    {
        $this->assertSame('v2.0.0', Version::format('V2.0.0'));
    }

    public function test_a_pre_release_tag_keeps_its_suffix(): void
    {
        $this->assertSame('v1.2.0-beta1', Version::format('1.2.0-beta1'));
    }

    /**
     * A branch install must stay readable. Blindly prefixing produced "vdev-main".
     */
    public function test_a_branch_version_is_left_alone(): void
    {
        $this->assertSame('dev-main', Version::format('dev-main'));
        $this->assertSame('dev-feature/x', Version::format('dev-feature/x'));
    }

    public function test_an_empty_version_falls_back(): void
    {
        $this->assertSame(Version::FALLBACK, Version::format(''));
        $this->assertSame(Version::FALLBACK, Version::format('   '));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('v1.0.0', Version::format('  v1.0.0  '));
    }

    /**
     * Formatting must be stable: running it twice cannot keep adding prefixes.
     */
    public function test_formatting_is_idempotent(): void
    {
        foreach (['v1.1.2', '1.1.2', 'dev-main', ''] as $input) {
            $once = Version::format($input);
            $this->assertSame($once, Version::format($once), "not idempotent for '$input'");
        }
    }

    public function test_current_returns_something_printable(): void
    {
        // Outside a composer install this is the fallback; inside one it is a version.
        $this->assertNotSame('', Version::current());
    }
}
