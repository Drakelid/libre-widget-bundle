<?php

namespace Drakelid\NmsDashWidgets\Tests\Unit;

use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use PHPUnit\Framework\TestCase;

/**
 * Covers DeviceGroups::ids() only. The remaining methods touch Eloquent and are
 * exercised against the live instance instead.
 *
 * `instanceof` does not autoload, so the App\Models\DeviceGroup reference inside
 * ids() is harmless outside a LibreNMS install.
 */
class DeviceGroupsIdsTest extends TestCase
{
    public function test_it_reads_the_array_of_strings_the_database_actually_stores(): void
    {
        // Exactly as it appears in users_widgets.settings.
        $this->assertSame([6, 2, 1], DeviceGroups::ids(['6', '2', '1']));
    }

    public function test_selection_order_is_preserved(): void
    {
        // Several widgets display groups in the order the user picked them.
        $this->assertSame([9, 3, 27], DeviceGroups::ids(['9', '3', '27']));
    }

    public function test_it_accepts_a_comma_separated_string(): void
    {
        $this->assertSame([1, 2, 3], DeviceGroups::ids('1,2,3'));
    }

    public function test_it_accepts_a_json_encoded_array(): void
    {
        $this->assertSame([14, 15], DeviceGroups::ids('[14,15]'));
    }

    public function test_it_accepts_a_bare_scalar(): void
    {
        $this->assertSame([3], DeviceGroups::ids(3));
        $this->assertSame([3], DeviceGroups::ids('3'));
    }

    public function test_it_flattens_nested_arrays(): void
    {
        $this->assertSame([1, 2, 3], DeviceGroups::ids([[1, 2], [3]]));
    }

    public function test_duplicates_are_removed_keeping_first_position(): void
    {
        $this->assertSame([5, 3], DeviceGroups::ids(['5', '3', '5']));
    }

    public function test_junk_and_non_positive_ids_are_dropped(): void
    {
        $this->assertSame([7], DeviceGroups::ids(['0', '-1', 'abc', null, '', '7']));
    }

    public function test_empty_inputs_produce_an_empty_list(): void
    {
        $this->assertSame([], DeviceGroups::ids(null));
        $this->assertSame([], DeviceGroups::ids([]));
        $this->assertSame([], DeviceGroups::ids(''));
        $this->assertSame([], DeviceGroups::ids('   '));
        $this->assertSame([], DeviceGroups::ids(false));
    }

    public function test_the_large_live_selection_round_trips(): void
    {
        // The widest real selection in users-widgets.json: 23 groups.
        $stored = ['6', '2', '1', '9', '27', '5', '3', '4', '33', '12', '13', '8',
            '14', '15', '25', '17', '18', '16', '19', '11', '20', '24', '22'];

        $ids = DeviceGroups::ids($stored);

        $this->assertCount(23, $ids);
        $this->assertSame(6, $ids[0]);
        $this->assertSame(22, $ids[22]);
        $this->assertContainsOnly('int', $ids);
    }
}
