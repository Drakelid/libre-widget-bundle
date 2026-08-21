<?php

namespace Drakelid\NmsDashWidgets\Support;

use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Device group selection and filtering.
 *
 * Replaces four near-identical private helpers from the original widgets
 * (normalizeGroupIds / cleanIds x2 / resolveDeviceGroupIds), adopting the most
 * tolerant input handling of the four.
 */
final class DeviceGroups
{
    /**
     * Normalise a stored device group setting to a list of positive ints.
     *
     * Accepts what the live rows actually contain and what older versions wrote:
     * an array of ints or strings (["6","2","1"]), a JSON string, or a comma
     * separated string. Order is preserved -- several widgets display groups in
     * the order the user picked them.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            $value = is_array($decoded) ? $decoded : explode(',', $trimmed);
        }

        if ($value instanceof DeviceGroup) {
            $value = [$value->id];
        }

        if (is_numeric($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach (self::flatten($value) as $id) {
            if ($id instanceof DeviceGroup) {
                $id = $id->id;
            }

            if (! is_numeric($id)) {
                continue;
            }

            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Restrict a query to devices belonging to any of the given groups.
     *
     * Do NOT use core's scopeInDeviceGroup() for this. It takes a single group id:
     *
     *     ->where('device_group_id', $deviceGroup)
     *
     * Passing an array produces wrong results. Core's own widgets only ever pass a
     * scalar, which is why the scope was written that way.
     *
     * whereIntegerInRaw sidesteps the ~1000 item bind limit; groups here can hold
     * hundreds of devices and a user may select twenty groups at once.
     *
     * @param  list<int>  $groupIds
     */
    public static function scopeToDevices(Builder $query, array $groupIds, string $column = 'device_id'): Builder
    {
        if (empty($groupIds)) {
            return $query;
        }

        $qualified = $query->qualifyColumn($column);

        return $query->whereIn($qualified, function ($sub) use ($groupIds): void {
            $sub->select('device_id')
                ->from('device_group_device')
                ->whereIntegerInRaw('device_group_id', $groupIds);
        });
    }

    /**
     * Groups the user may select, ordered by name.
     *
     * @return Collection<int, DeviceGroup>
     */
    public static function selectable(?User $user): Collection
    {
        $query = DeviceGroup::query();

        if ($user !== null) {
            $query->hasAccess($user);
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Filter a list of ids down to the groups the user may actually see, preserving
     * the user's ordering.
     *
     * Without this a user could hand-edit a group id into their settings JSON and
     * read counts for a group they have no rights to.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function accessibleIds(?User $user, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $query = DeviceGroup::query()->whereIntegerInRaw('id', $ids);

        if ($user !== null) {
            $query->hasAccess($user);
        }

        $allowed = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $allowed, true)));
    }

    /**
     * Selected groups as models, in the user's selection order.
     *
     * @param  list<int>  $ids
     * @return Collection<int, DeviceGroup>
     */
    public static function ordered(?User $user, array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $query = DeviceGroup::query()->whereIntegerInRaw('id', $ids);

        if ($user !== null) {
            $query->hasAccess($user);
        }

        return $query->get()
            ->sortBy(fn ($group) => array_search((int) $group->id, $ids, true))
            ->values();
    }

    /**
     * Comma separated group names for the widget subtitle.
     *
     * @param  list<int>  $ids
     */
    public static function namesFor(?User $user, array $ids, string $emptyLabel): string
    {
        if (empty($ids)) {
            return $emptyLabel;
        }

        $query = DeviceGroup::query()->whereIntegerInRaw('id', $ids);

        if ($user !== null) {
            $query->hasAccess($user);
        }

        $names = $query->orderBy('name')->pluck('name')->implode(', ');

        return $names !== '' ? $names : $emptyLabel;
    }

    /**
     * Map device id => comma separated names of the selected groups it belongs to.
     *
     * One query for the whole page rather than one per row.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $deviceIds
     * @return Collection<int, string>
     */
    public static function membershipMap(array $groupIds, array $deviceIds, ?User $user = null): Collection
    {
        if (empty($deviceIds)) {
            return collect();
        }

        // An empty selection means "all accessible devices", not "no groups". Filtering
        // by an empty id list returned nothing, so the Device group column -- which is
        // on by default -- rendered blank on every row until a group was picked. With
        // nothing selected, name every group the device belongs to that the user can see.
        if (empty($groupIds)) {
            $groupIds = self::selectable($user)->pluck('id')->all();
        }

        if (empty($groupIds)) {
            return collect();
        }

        return DB::table('device_group_device')
            ->join('device_groups', 'device_groups.id', '=', 'device_group_device.device_group_id')
            ->whereIntegerInRaw('device_group_device.device_group_id', $groupIds)
            ->whereIntegerInRaw('device_group_device.device_id', $deviceIds)
            ->select('device_group_device.device_id', 'device_groups.name')
            ->orderBy('device_groups.name')
            ->get()
            ->groupBy('device_id')
            ->map(fn ($rows) => $rows->pluck('name')->implode(', '));
    }

    /**
     * @return list<mixed>
     */
    private static function flatten(array $value): array
    {
        $out = [];

        array_walk_recursive($value, function ($item) use (&$out): void {
            $out[] = $item;
        });

        return $out;
    }
}
