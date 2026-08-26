<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Attraction;
use App\Models\Event;
use App\Models\Location;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything a targeting picker needs, in one request: the venues plus every package,
 * attraction and event a staff member is allowed to point something at.
 *
 * Deliberately selects narrow columns. Packages and attractions carry image payloads
 * measured in megabytes, and pulling whole rows here is what made the old picker time
 * out and show nothing at all.
 */
class TargetingOptionController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveAuthUser($request);

        // No company to scope by means no catalog. Without this, an unscoped caller read
        // every company's venues and item names.
        if (!$user?->company_id) {
            return response()->json([
                'success' => true,
                'data' => ['locations' => [], 'packages' => [], 'attractions' => [], 'events' => []],
            ]);
        }

        $locations = Location::query()
            ->where('company_id', $user->company_id)
            ->when(
                $user && in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id,
                fn ($q) => $q->where('id', $user->location_id),
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        $locationIds = $locations->pluck('id')->all();

        if (empty($locationIds)) {
            return response()->json([
                'success' => true,
                'data' => ['locations' => [], 'packages' => [], 'attractions' => [], 'events' => []],
            ]);
        }

        $items = function (string $model, array $columns) use ($locationIds) {
            return $model::query()
                ->whereIn('location_id', $locationIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get($columns)
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'location_id' => (int) $row->location_id,
                    'category' => $row->category ?? null,
                ])
                ->values();
        };

        return response()->json([
            'success' => true,
            'data' => [
                'locations' => $locations->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->values(),
                'packages' => $items(Package::class, ['id', 'name', 'location_id', 'category']),
                'attractions' => $items(Attraction::class, ['id', 'name', 'location_id', 'category']),
                'events' => $items(Event::class, ['id', 'name', 'location_id']),
            ],
        ]);
    }
}
