<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Select;

use App\Models\DeviceGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * select2 data source for the device group pickers in the widget settings forms.
 *
 * Results are always scoped with hasAccess() so the picker cannot be used to
 * enumerate device groups the user has no rights to.
 */
class DeviceGroupsController extends Controller
{
    private const PAGE_SIZE = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('term', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = DeviceGroup::query()
            ->hasAccess($request->user())
            ->when($term !== '', function ($q) use ($term): void {
                $escaped = addcslashes($term, '%_\\');
                $q->where('name', 'like', '%' . $escaped . '%');
            })
            ->orderBy('name');

        $total = (clone $query)->count();

        $groups = $query
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->get(['id', 'name']);

        return response()->json([
            'results' => $groups->map(fn (DeviceGroup $group): array => [
                'id' => (int) $group->id,
                'text' => $group->name,
            ])->values(),
            'pagination' => [
                'more' => ($page * self::PAGE_SIZE) < $total,
            ],
        ]);
    }
}
