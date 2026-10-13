<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\DayOff;
use App\Models\DayOffChangeLog;
use App\Models\Event;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use App\Http\Traits\ScopesByAuthUser;
use App\Services\DayOffPresenter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DayOffController extends Controller
{
    use ScopesByAuthUser;

    private const SCOPES = ['location', 'packages', 'rooms', 'attractions', 'events'];

    private const BLOCK_TYPES = [DayOff::BLOCK_FULL_DAY, DayOff::BLOCK_OPERATING_WINDOW];

    private const TARGETS = [
        'packages' => ['package_ids', Package::class, 'package', 'packages'],
        'rooms' => ['room_ids', Room::class, 'space', 'spaces'],
        'attractions' => ['attraction_ids', Attraction::class, 'attraction', 'attractions'],
        'events' => ['event_ids', Event::class, 'event', 'events'],
    ];

    private const MAX_CALENDAR_DAYS = 120;

    public function __construct(private DayOffPresenter $presenter)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = DayOff::query();

        $this->applyAuthScope($query, $request);
        $query->whereIn('location_id', $this->visibleLocationIds($request));

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->byDateRange($request->start_date, $request->end_date);
        }

        if ($request->has('is_recurring')) {
            $query->where('is_recurring', $request->boolean('is_recurring'));
        }

        if ($request->has('package_id')) {
            $query->forPackage($request->package_id);
        }

        if ($request->has('room_id')) {
            $query->forRoom($request->room_id);
        }

        if ($request->has('attraction_id')) {
            $query->forAttraction($request->attraction_id);
        }

        if ($request->has('event_id')) {
            $query->forEvent($request->event_id);
        }

        if ($request->boolean('location_wide_only')) {
            $query->locationWide();
        }

        if ($request->boolean('upcoming_only')) {
            $query->upcoming();
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            $searchesGroups = DayOff::hasOperatingWindowColumns();
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $searchesGroups) {
                    $q->where('reason', 'like', $like);
                    if ($searchesGroups) {
                        $q->orWhere('group_name', 'like', $like);
                    }
                });
            }
        }

        $sortBy = $request->get('sort_by', 'date');
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        if (in_array($sortBy, ['date', 'created_at', 'updated_at'])) {
            $query->orderBy($sortBy, $sortOrder)->orderBy('id', $sortOrder);
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 15)));
        $dayOffs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'day_offs' => $this->presentWithRecord($dayOffs->getCollection()),
                'pagination' => [
                    'current_page' => $dayOffs->currentPage(),
                    'last_page' => $dayOffs->lastPage(),
                    'per_page' => $dayOffs->perPage(),
                    'total' => $dayOffs->total(),
                    'from' => $dayOffs->firstItem(),
                    'to' => $dayOffs->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($unavailable = $this->unavailableUntilMigrated()) {
            return $unavailable;
        }

        $validated = $request->validate($this->rules(true));

        $location = $this->scopedLocation($request, $validated['location_id']);
        if ($location instanceof JsonResponse) {
            return $location;
        }

        if ($denied = $this->outsideManagedLocations($request, (int) $validated['location_id'])) {
            return $denied;
        }

        $attributes = [
            'location_id' => (int) $validated['location_id'],
            'date' => Carbon::parse($validated['date'])->toDateString(),
            'is_recurring' => (bool) ($validated['is_recurring'] ?? false),
            'reason' => trim((string) $validated['reason']),
            'group_name' => trim((string) $validated['group_name']),
        ] + $this->scheduleAttributes($validated['block_type'], $validated['opens_at'] ?? null, $validated['closes_at'] ?? null)
          + $this->targetAttributes($validated['scope'], $validated);

        if ($error = $this->scheduleError($validated['block_type'], $attributes)) {
            return $error;
        }

        if ($error = $this->leapDayError($attributes)) {
            return $error;
        }

        if ($error = $this->scopeTargetsError($validated['scope'], $attributes)) {
            return $error;
        }

        if ($error = $this->targetsError($attributes)) {
            return $error;
        }

        $actor = $this->staffActor($request);
        $attributes['created_by'] = $actor?->id;
        $attributes['updated_by'] = $actor?->id;

        $result = DB::transaction(function () use ($attributes, $actor, $request) {
            Location::whereKey($attributes['location_id'])->lockForUpdate()->first();

            if ($conflict = $this->conflictError($attributes, [])) {
                return $conflict;
            }

            $dayOff = DayOff::create($attributes);

            DayOffChangeLog::record(
                DayOffChangeLog::ACTION_CREATED,
                $dayOff,
                $actor,
                $this->summarize('Blocked', $dayOff),
                null,
                $dayOff->snapshot(),
                [],
                $request->ip(),
            );

            return $dayOff;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $dayOff = $result;
        $presented = $this->presenter->presentOne($dayOff);

        ActivityLog::log(
            action: 'Day Off Created',
            category: 'create',
            description: "Day off created for {$presented['date']} ({$presented['scope_label']}, {$presented['window_label']}) for {$dayOff->group_name}: {$dayOff->reason}",
            userId: $actor?->id,
            locationId: $dayOff->location_id,
            entityType: 'day_off',
            entityId: $dayOff->id,
            metadata: [
                'created_by' => $this->actorMetadata($actor),
                'created_at' => now()->toIso8601String(),
                'day_off_details' => $dayOff->snapshot() + [
                    'scope' => $presented['scope_label'],
                    'window' => $presented['window_label'],
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off created successfully',
            'data' => $this->presentWithRecord(collect([$dayOff->fresh()]))[0],
        ], 201);
    }

    public function show(DayOff $dayOff): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($dayOff, 'closure')) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentWithRecord(collect([$dayOff]))[0],
        ]);
    }

    public function update(Request $request, DayOff $dayOff): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($dayOff, 'closure')) {
            return $denied;
        }

        if ($unavailable = $this->unavailableUntilMigrated()) {
            return $unavailable;
        }

        $validated = $request->validate($this->rules(false));

        $locationChanges = array_key_exists('location_id', $validated)
            && (int) $validated['location_id'] !== (int) $dayOff->location_id;

        if ($denied = $this->outsideManagedLocations($request, (int) $dayOff->location_id)) {
            return $denied;
        }

        if ($locationChanges) {
            $location = $this->scopedLocation($request, $validated['location_id']);
            if ($location instanceof JsonResponse) {
                return $location;
            }

            if ($denied = $this->outsideManagedLocations($request, (int) $validated['location_id'])) {
                return $denied;
            }
        }

        $sendsBlockType = array_key_exists('block_type', $validated);
        $touchesHours = $sendsBlockType
            || array_key_exists('opens_at', $validated)
            || array_key_exists('closes_at', $validated);

        if ($touchesHours && ! $sendsBlockType) {
            return $this->invalid('block_type', 'Choose whether this is a full-day closure or an operating window.');
        }

        $sendsScope = array_key_exists('scope', $validated);
        $touchesTargets = $sendsScope || collect(self::TARGETS)->contains(
            fn (array $target) => array_key_exists($target[0], $validated)
        );

        if ($touchesTargets && ! $sendsScope) {
            return $this->invalid('scope', 'Choose what this block applies to.');
        }

        $currentDate = Carbon::parse($dayOff->date)->toDateString();
        $dateChanges = array_key_exists('date', $validated)
            && Carbon::parse($validated['date'])->toDateString() !== $currentDate;
        $recurrenceChanges = array_key_exists('is_recurring', $validated)
            && (bool) $validated['is_recurring'] !== (bool) $dayOff->is_recurring;
        $movesBlock = $dateChanges || $recurrenceChanges || $locationChanges || $touchesTargets;

        if ($dayOff->block_type === DayOff::BLOCK_LEGACY_CLOSURE && $movesBlock && ! $sendsBlockType) {
            return $this->invalid('block_type', 'This block uses the older closed-hours format. Choose a full-day closure or one operating window before changing its date or what it applies to.');
        }

        $next = [];

        if ($locationChanges) {
            $next['location_id'] = (int) $validated['location_id'];
        }

        if (array_key_exists('date', $validated)) {
            $next['date'] = Carbon::parse($validated['date'])->toDateString();
        }

        if (array_key_exists('is_recurring', $validated)) {
            $next['is_recurring'] = (bool) $validated['is_recurring'];
        }

        foreach (['reason', 'group_name'] as $text) {
            if (array_key_exists($text, $validated)) {
                $next[$text] = trim((string) $validated[$text]);
            }
        }

        if ($sendsBlockType) {
            $keepsWindow = $validated['block_type'] === DayOff::BLOCK_OPERATING_WINDOW && $dayOff->isOperatingWindow();
            $opensAt = array_key_exists('opens_at', $validated) ? $validated['opens_at'] : ($keepsWindow ? $dayOff->opens_at : null);
            $closesAt = array_key_exists('closes_at', $validated) ? $validated['closes_at'] : ($keepsWindow ? $dayOff->closes_at : null);
            $next += $this->scheduleAttributes($validated['block_type'], $opensAt, $closesAt);
        }

        if ($sendsScope) {
            $next += $this->targetAttributes($validated['scope'], $validated);
        }

        $candidate = (clone $dayOff)->fill($next);
        $merged = $candidate->snapshot();

        if (trim((string) $merged['reason']) === '') {
            return $this->invalid('reason', 'Enter the reason for this block.');
        }

        if (trim((string) $merged['group_name']) === '') {
            return $this->invalid('group_name', 'Enter the group or event name for this block. Older blocks need one before they can be changed.');
        }

        if ($sendsBlockType && ($error = $this->scheduleError($validated['block_type'], $merged))) {
            return $error;
        }

        if (($dateChanges || $recurrenceChanges) && ($error = $this->leapDayError($merged))) {
            return $error;
        }

        if ($sendsScope && ($error = $this->scopeTargetsError($validated['scope'], $merged))) {
            return $error;
        }

        if (($sendsScope || $locationChanges) && ($error = $this->targetsError($merged))) {
            return $error;
        }

        $before = $dayOff->snapshot();
        $comparableBefore = $before;
        $comparableAfter = $merged;
        unset($comparableBefore['updated_by'], $comparableAfter['updated_by']);

        if ($comparableBefore == $comparableAfter) {
            return response()->json([
                'success' => true,
                'message' => 'Nothing changed',
                'data' => $this->presentWithRecord(collect([$dayOff]))[0],
            ]);
        }

        $actor = $this->staffActor($request);
        $next['updated_by'] = $actor?->id;
        $checksConflicts = $movesBlock || $touchesHours;
        $mergesOlderPair = $dayOff->block_type === DayOff::BLOCK_LEGACY_CLOSURE
            && $sendsBlockType
            && ! $dateChanges
            && ! $recurrenceChanges
            && ! $locationChanges
            && $this->targetsOf($merged) == $this->targetsOf($before);
        $mergedAway = [];

        $result = DB::transaction(function () use ($dayOff, $next, $merged, $before, $actor, $request, $checksConflicts, $mergesOlderPair, &$mergedAway) {
            Location::whereKey($merged['location_id'])->lockForUpdate()->first();

            $current = DayOff::whereKey($dayOff->id)->lockForUpdate()->first();

            if (! $current) {
                return $this->goneResponse();
            }

            if ($current->snapshot() != $before) {
                return $this->changedElsewhereResponse();
            }

            $siblings = $mergesOlderPair ? $this->olderSiblingsOf($current, $merged) : collect();
            $uncovered = $siblings->first(fn (DayOff $sibling) => ! $this->windowCovers($merged, $sibling));

            if ($uncovered) {
                $lost = $this->presenter->windowLabel($uncovered);

                return response()->json([
                    'success' => false,
                    'message' => "Another older block for the same day ({$lost}) is not inside these hours, so saving would lose it. Choose hours that include it, or remove that block first.",
                    'errors' => ['opens_at' => ["These hours leave out the older block ({$lost})."]],
                    'conflict_day_off_id' => (int) $uncovered->id,
                ], 422);
            }
            $ignoreIds = array_merge([(int) $dayOff->id], $siblings->map(fn (DayOff $sibling) => (int) $sibling->id)->all());

            if (($checksConflicts || $siblings->isNotEmpty()) && ($conflict = $this->conflictError($merged, $ignoreIds))) {
                return $conflict;
            }

            foreach ($siblings as $sibling) {
                $snapshot = $sibling->snapshot();

                DayOffChangeLog::record(
                    DayOffChangeLog::ACTION_DELETED,
                    $sibling,
                    $actor,
                    $this->summarize('Removed', $sibling) . ' (merged into the converted block)',
                    $snapshot,
                    null,
                    ['merged_into_day_off_id' => (int) $dayOff->id],
                    $request->ip(),
                );

                $sibling->delete();
                $mergedAway[] = $snapshot;
            }

            $current->fill($next)->save();
            $dayOff->setRawAttributes($current->fresh()->getAttributes(), true);

            DayOffChangeLog::record(
                DayOffChangeLog::ACTION_UPDATED,
                $dayOff,
                $actor,
                $this->summarize('Changed', $dayOff),
                $before,
                $dayOff->snapshot(),
                [],
                $request->ip(),
            );

            return $dayOff;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $presented = $this->presenter->presentOne($dayOff);

        ActivityLog::log(
            action: 'Day Off Updated',
            category: 'update',
            description: "Day off updated for {$presented['date']} ({$presented['scope_label']}, {$presented['window_label']})",
            userId: $actor?->id,
            locationId: $dayOff->location_id,
            entityType: 'day_off',
            entityId: $dayOff->id,
            metadata: [
                'updated_by' => $this->actorMetadata($actor),
                'updated_at' => now()->toIso8601String(),
                'updated_fields' => array_keys($next),
                'before' => $before,
                'day_off_details' => $dayOff->snapshot(),
            ]
        );

        foreach ($mergedAway as $removed) {
            ActivityLog::log(
                action: 'Day Off Deleted',
                category: 'delete',
                description: "Day off for '{$removed['date']}' was merged into day off #{$dayOff->id}",
                userId: $actor?->id,
                locationId: $removed['location_id'],
                entityType: 'day_off',
                entityId: $removed['id'],
                metadata: [
                    'deleted_by' => $this->actorMetadata($actor),
                    'deleted_at' => now()->toIso8601String(),
                    'day_off_details' => $removed,
                    'merged_into_day_off_id' => (int) $dayOff->id,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Day off updated successfully',
            'data' => $this->presentWithRecord(collect([$dayOff]))[0],
            'merged_day_off_ids' => array_map(fn (array $removed) => (int) $removed['id'], $mergedAway),
        ]);
    }

    public function destroy(Request $request, DayOff $dayOff): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($dayOff, 'closure')) {
            return $denied;
        }

        if ($denied = $this->outsideManagedLocations($request, (int) $dayOff->location_id)) {
            return $denied;
        }

        if ($unavailable = $this->unavailableUntilMigrated()) {
            return $unavailable;
        }

        $actor = $this->staffActor($request);

        $removed = DB::transaction(function () use ($dayOff, $actor, $request) {
            $current = DayOff::whereKey($dayOff->id)->lockForUpdate()->first();

            if (! $current) {
                return null;
            }

            $before = $current->snapshot();

            DayOffChangeLog::record(
                DayOffChangeLog::ACTION_DELETED,
                $current,
                $actor,
                $this->summarize('Removed', $current),
                $before,
                null,
                [],
                $request->ip(),
            );

            $current->delete();

            return $before;
        });

        if ($removed === null) {
            return $this->goneResponse();
        }

        $before = $removed;

        ActivityLog::log(
            action: 'Day Off Deleted',
            category: 'delete',
            description: "Day off for '{$before['date']}' was deleted",
            userId: $actor?->id,
            locationId: $before['location_id'],
            entityType: 'day_off',
            entityId: $before['id'],
            metadata: [
                'deleted_by' => $this->actorMetadata($actor),
                'deleted_at' => now()->toIso8601String(),
                'day_off_details' => $before,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off deleted successfully',
        ]);
    }

    public function getByLocation(Request $request, int $locationId): JsonResponse
    {
        if ($denied = $this->guardLocationAccess($request, $locationId)) {
            return $denied;
        }

        $dayOffs = DayOff::byLocation($locationId)
            ->where(function ($q) {
                $q->where('date', '>=', now()->toDateString())
                  ->orWhere('is_recurring', true);
            })
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $withDetails = $this->staffMaySeeDetails($request->user('sanctum'), $locationId);
        $details = $withDetails ? collect($this->presenter->presentMany($dayOffs))->keyBy('id') : collect();

        $closures = [];

        foreach ($dayOffs as $dayOff) {
            $record = $dayOff->toArray();

            foreach ($dayOff->asLegacyClosures() as $closure) {
                $entry = [
                    'id' => $dayOff->id,
                    'location_id' => (int) $dayOff->location_id,
                    'date' => $record['date'],
                    'is_recurring' => (bool) $dayOff->is_recurring,
                    'time_start' => $closure['time_start'],
                    'time_end' => $closure['time_end'],
                    'package_ids' => DayOff::normalizedIds($dayOff->package_ids),
                    'room_ids' => DayOff::normalizedIds($dayOff->room_ids),
                    'attraction_ids' => DayOff::normalizedIds($dayOff->attraction_ids),
                    'event_ids' => DayOff::normalizedIds($dayOff->event_ids),
                    'block_type' => $dayOff->block_type,
                ];

                if ($withDetails) {
                    $presented = $details->get($dayOff->id);
                    $entry += [
                        'reason' => $dayOff->reason,
                        'group_name' => $dayOff->group_name,
                        'window_label' => $presented['window_label'],
                        'scope_label' => $presented['scope_label'],
                        'set_by' => $presented['set_by'],
                    ];
                }

                $closures[] = $entry;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $closures,
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location_id' => 'nullable|integer',
        ]);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        if ($start->diffInDays($end) > self::MAX_CALENDAR_DAYS) {
            return $this->invalid('end_date', 'Ask for at most ' . self::MAX_CALENDAR_DAYS . ' days at a time.');
        }

        $locationIds = $this->visibleLocationIds($request);

        if (! empty($validated['location_id'])) {
            if ($denied = $this->guardLocationAccess($request, (int) $validated['location_id'])) {
                return $denied;
            }

            if (! in_array((int) $validated['location_id'], $locationIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: cannot access another location\'s data',
                ], 403);
            }

            $locationIds = [(int) $validated['location_id']];
        }

        $rows = DayOff::whereIn('location_id', $locationIds)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                  ->orWhere('is_recurring', true);
            })
            ->orderBy('id')
            ->get();

        $presented = collect($this->presenter->presentMany($rows))->keyBy('id');
        $occurrences = [];

        foreach ($rows as $dayOff) {
            $baseDate = Carbon::parse($dayOff->date);

            if (! $dayOff->is_recurring) {
                if ($baseDate->betweenIncluded($start, $end)) {
                    $occurrences[] = ['occurrence_date' => $baseDate->toDateString()] + $presented->get($dayOff->id);
                }

                continue;
            }

            for ($year = $start->year; $year <= $end->year; $year++) {
                if (! checkdate($baseDate->month, $baseDate->day, $year)) {
                    continue;
                }

                $occurrence = Carbon::create($year, $baseDate->month, $baseDate->day)->startOfDay();

                if ($occurrence->betweenIncluded($start, $end)) {
                    $occurrences[] = ['occurrence_date' => $occurrence->toDateString()] + $presented->get($dayOff->id);
                }
            }
        }

        usort($occurrences, fn (array $a, array $b) => [$a['occurrence_date'], $a['location_name'] ?? '', $a['id']]
            <=> [$b['occurrence_date'], $b['location_name'] ?? '', $b['id']]);

        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'occurrences' => $occurrences,
            ],
        ]);
    }

    public function changeLog(Request $request): JsonResponse
    {
        if (! DayOff::tracksAvailabilityDetails()) {
            return response()->json([
                'success' => true,
                'data' => ['changes' => [], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 0, 'total' => 0, 'from' => null, 'to' => null]],
            ]);
        }

        $validated = $request->validate([
            'day_off_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'action' => ['nullable', Rule::in([
                DayOffChangeLog::ACTION_CREATED,
                DayOffChangeLog::ACTION_UPDATED,
                DayOffChangeLog::ACTION_DELETED,
                DayOffChangeLog::ACTION_BOOKED_INSIDE,
                DayOffChangeLog::ACTION_BOOKING_REMOVED,
            ])],
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $locationIds = $this->visibleLocationIds($request);
        $viewer = $this->resolveAuthUser($request);
        $companyWide = $viewer instanceof User
            && $viewer->company_id
            && ! in_array($viewer->role, ['location_manager', 'attendant'], true);

        if (! empty($validated['location_id'])) {
            if (! in_array((int) $validated['location_id'], $locationIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: cannot access another location\'s data',
                ], 403);
            }

            $locationIds = [(int) $validated['location_id']];
            $companyWide = false;
        }

        $changes = DayOffChangeLog::query()
            ->where(function ($q) use ($locationIds, $companyWide, $viewer) {
                $q->whereIn('location_id', $locationIds)->orWhereIn('previous_location_id', $locationIds);

                if ($companyWide) {
                    $q->orWhere('company_id', (int) $viewer->company_id);
                }
            })
            ->when(! empty($validated['day_off_id']), fn ($q) => $q->where('day_off_id', (int) $validated['day_off_id']))
            ->when(! empty($validated['action']), fn ($q) => $q->where('action', $validated['action']))
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $locationNames = Location::whereIn('id', $changes->getCollection()
            ->flatMap(fn (DayOffChangeLog $change) => [$change->location_id, $change->previous_location_id])
            ->filter()
            ->unique())
            ->pluck('name', 'id');

        return response()->json([
            'success' => true,
            'data' => [
                'changes' => $changes->getCollection()->map(fn (DayOffChangeLog $change) => [
                    'id' => $change->id,
                    'day_off_id' => $change->day_off_id,
                    'location_id' => $change->location_id,
                    'location_name' => $locationNames[$change->location_id] ?? null,
                    'previous_location_id' => $change->previous_location_id,
                    'previous_location_name' => $change->previous_location_id ? ($locationNames[$change->previous_location_id] ?? null) : null,
                    'action' => $change->action,
                    'summary' => $change->summary,
                    'changed_by' => $change->changed_by ? [
                        'id' => (int) $change->changed_by,
                        'name' => $change->changed_by_name,
                        'email' => $change->changed_by_email,
                        'role' => $change->changed_by_role,
                    ] : null,
                    'before' => $change->before,
                    'after' => $change->after,
                    'changed_fields' => $change->changed_fields,
                    'context' => $change->context,
                    'created_at' => $change->created_at?->toIso8601String(),
                ])->values(),
                'pagination' => [
                    'current_page' => $changes->currentPage(),
                    'last_page' => $changes->lastPage(),
                    'per_page' => $changes->perPage(),
                    'total' => $changes->total(),
                    'from' => $changes->firstItem(),
                    'to' => $changes->lastItem(),
                ],
            ],
        ]);
    }

    public function checkDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i',
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);
        if ($location instanceof JsonResponse) {
            return $location;
        }

        if (isset($validated['time_start'])) {
            $isBlocked = DayOff::isTimeSlotBlocked(
                $validated['location_id'],
                $validated['date'],
                $validated['time_start'],
                $validated['time_end'] ?? null
            );

            $dayOff = $isBlocked ? DayOff::getDayOffForTimeSlot(
                $validated['location_id'],
                $validated['date'],
                $validated['time_start'],
                $validated['time_end'] ?? null
            ) : null;
        } else {
            $isBlocked = DayOff::isDateBlocked($validated['location_id'], $validated['date']);

            $dayOff = null;
            if ($isBlocked) {
                $dayOff = DayOff::where('location_id', $validated['location_id'])
                    ->forDate($validated['date'])
                    ->allDay()
                    ->locationWide()
                    ->first();
            }
        }

        $allDayOffs = DayOff::where('location_id', $validated['location_id'])
            ->forDate($validated['date'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'is_blocked' => $isBlocked,
                'day_off' => $dayOff,
                'all_day_offs' => $allDayOffs,
            ],
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        if ($unavailable = $this->unavailableUntilMigrated()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:day_offs,id',
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $actor = $this->staffActor($request);
        $visibleLocationIds = $this->visibleLocationIds($request);
        $deletedIds = [];

        foreach ($ids as $id) {
            $dayOff = DayOff::find($id);

            if (! $dayOff || ! $this->authorizeRecordScope($dayOff) || ! in_array((int) $dayOff->location_id, $visibleLocationIds, true)) {
                continue;
            }

            $before = DB::transaction(function () use ($dayOff, $actor, $request) {
                $current = DayOff::whereKey($dayOff->id)->lockForUpdate()->first();

                if (! $current) {
                    return null;
                }

                $snapshot = $current->snapshot();

                DayOffChangeLog::record(
                    DayOffChangeLog::ACTION_DELETED,
                    $current,
                    $actor,
                    $this->summarize('Removed', $current),
                    $snapshot,
                    null,
                    ['bulk_operation' => true],
                    $request->ip(),
                );

                $current->delete();

                return $snapshot;
            });

            if ($before === null) {
                continue;
            }

            $deletedIds[] = $id;

            ActivityLog::log(
                action: 'Day Off Bulk Deleted',
                category: 'delete',
                description: "Day off for '{$before['date']}' was deleted via bulk operation",
                userId: $actor?->id,
                locationId: $before['location_id'],
                entityType: 'day_off',
                entityId: $id,
                metadata: [
                    'deleted_by' => $this->actorMetadata($actor),
                    'deleted_at' => now()->toIso8601String(),
                    'day_off_details' => $before,
                    'bulk_operation' => true,
                ]
            );
        }

        $deletedCount = count($deletedIds);

        ActivityLog::log(
            action: 'Day Offs Bulk Delete',
            category: 'delete',
            description: "Bulk deleted {$deletedCount} day offs",
            userId: $actor?->id,
            metadata: [
                'deleted_by' => $this->actorMetadata($actor),
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'day_off_ids' => $deletedIds,
                'requested_ids' => $ids,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} day off(s) deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'location_id' => "{$required}|exists:locations,id",
            'date' => "{$required}|date",
            'block_type' => [$required, Rule::in(self::BLOCK_TYPES)],
            'opens_at' => 'sometimes|nullable|date_format:H:i',
            'closes_at' => 'sometimes|nullable|date_format:H:i',
            'time_start' => 'prohibited',
            'time_end' => 'prohibited',
            'reason' => "{$required}|string|max:255",
            'group_name' => "{$required}|string|max:150",
            'is_recurring' => 'sometimes|boolean',
            'scope' => [$required, Rule::in(self::SCOPES)],
        ];

        foreach (self::TARGETS as $scope => [$column, $model]) {
            $table = (new $model())->getTable();
            $rules[$column] = "sometimes|nullable|array|required_if:scope,{$scope}";
            $rules["{$column}.*"] = "integer|distinct|exists:{$table},id";
        }

        return $rules;
    }

    private function scheduleAttributes(string $blockType, ?string $opensAt, ?string $closesAt): array
    {
        if ($blockType === DayOff::BLOCK_FULL_DAY) {
            return ['time_start' => null, 'time_end' => null, 'opens_at' => null, 'closes_at' => null];
        }

        return [
            'time_start' => null,
            'time_end' => null,
            'opens_at' => DayOff::shortClock($opensAt),
            'closes_at' => DayOff::shortClock($closesAt),
        ];
    }

    private function targetAttributes(string $scope, array $input): array
    {
        $attributes = [];

        foreach (self::TARGETS as $targetScope => [$column]) {
            $attributes[$column] = $targetScope === $scope ? DayOff::normalizedIds($input[$column] ?? null) : null;
        }

        return $attributes;
    }

    private function scheduleError(string $blockType, array $attributes): ?JsonResponse
    {
        if ($blockType !== DayOff::BLOCK_OPERATING_WINDOW) {
            return null;
        }

        $opensAt = DayOff::clockToMinutes($attributes['opens_at'] ?? null);
        $closesAt = DayOff::overnightClose($opensAt, DayOff::closingClockToMinutes($attributes['closes_at'] ?? null));

        if ($opensAt === null && $closesAt === null) {
            return $this->invalid('opens_at', 'Enter the time it opens, the time it closes, or both.');
        }

        if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt) {
            return $this->invalid('closes_at', 'The closing time must be later than the opening time, or no later than 6:00 AM the next morning.');
        }

        if (($opensAt === null || $opensAt === 0) && ($closesAt === null || $closesAt >= DayOff::MINUTES_PER_DAY)) {
            return $this->invalid('opens_at', 'Those hours keep the whole day open, so nothing would be blocked. Choose a later opening time or an earlier closing time, or use a full-day closure.');
        }

        return null;
    }

    private function leapDayError(array $attributes): ?JsonResponse
    {
        if (empty($attributes['is_recurring'])) {
            return null;
        }

        $date = Carbon::parse($attributes['date']);

        if ($date->month !== 2 || $date->day !== 29) {
            return null;
        }

        return $this->invalid('date', 'A yearly block cannot fall on February 29, because most years do not have that day. Use February 28 or March 1, or make it a one-time block.');
    }

    private function scopeTargetsError(string $scope, array $attributes): ?JsonResponse
    {
        if ($scope === 'location') {
            return null;
        }

        [$column, , , $plural] = self::TARGETS[$scope];

        if (empty($attributes[$column])) {
            return $this->invalid($column, "Choose at least one of the {$plural} to block.");
        }

        return null;
    }

    private function targetsError(array $attributes): ?JsonResponse
    {
        foreach (self::TARGETS as [$column, $model, $singular, $plural]) {
            $ids = DayOff::normalizedIds($attributes[$column] ?? null);

            if ($ids === null) {
                continue;
            }

            $found = $model::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('location_id', $attributes['location_id'])
                ->count();

            if ($found !== count($ids)) {
                return $this->invalid($column, "Some of the selected {$plural} belong to a different location. Choose {$plural} from the location being blocked.");
            }
        }

        return null;
    }

    private function conflictError(array $candidate, array $ignoreIds): ?JsonResponse
    {
        $date = Carbon::parse($candidate['date']);
        $today = Carbon::today()->toDateString();
        $candidateRecurring = (bool) ($candidate['is_recurring'] ?? false);

        $rows = DayOff::where('location_id', $candidate['location_id'])
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->whereMonth('date', $date->month)
            ->whereDay('date', $date->day)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($rows as $existing) {
            $existingDate = Carbon::parse($existing->date)->toDateString();
            $sameDay = $existingDate === $date->toDateString();

            if (! $sameDay) {
                if (! $existing->is_recurring && ! $candidateRecurring) {
                    continue;
                }

                if (! $existing->is_recurring && $existingDate < $today) {
                    continue;
                }
            }

            $item = $this->sharedItem($candidate, $existing);

            if ($item === null && $this->splitsAroundOlderClosure($candidate, $existing)) {
                $closedLabel = $this->presenter->windowLabel($existing);
                $closedWhen = $sameDay || ! $existing->is_recurring
                    ? Carbon::parse($existingDate)->format('D, M j, Y')
                    : 'every ' . $date->format('F j');

                return response()->json([
                    'success' => false,
                    'message' => "An older closed-hours block on {$closedWhen} ({$closedLabel}) already splits that day for some of the same items, so this block would leave them two separate windows. Remove or change the older block first.",
                    'errors' => ['date' => ["An older closed-hours block already splits {$closedWhen}."]],
                    'conflict_day_off_id' => (int) $existing->id,
                ], 422);
            }

            if ($item === null) {
                continue;
            }

            if ($sameDay || $existing->is_recurring && ! $candidateRecurring) {
                $when = $date->format('D, M j, Y');
            } elseif (! $existing->is_recurring) {
                $when = Carbon::parse($existingDate)->format('D, M j, Y');
            } else {
                $when = 'every ' . $date->format('F j');
            }

            $existingLabel = $this->presenter->windowLabel($existing);
            $existingName = $existing->group_name ?: ($existing->reason ?: 'no name');

            return response()->json([
                'success' => false,
                'message' => "{$item} already has a block on {$when} ({$existingName}: {$existingLabel}). Each item can have only one block a day, so edit that block instead.",
                'errors' => ['date' => ["{$item} already has a block on {$when}."]],
                'conflict_day_off_id' => (int) $existing->id,
            ], 422);
        }

        return null;
    }

    private function targetsOf(array $attributes): array
    {
        return collect(self::TARGETS)
            ->mapWithKeys(fn (array $target) => [$target[0] => DayOff::normalizedIds($attributes[$target[0]] ?? null)])
            ->all();
    }

    private function windowCovers(array $merged, DayOff $sibling): bool
    {
        $opensAt = DayOff::clockToMinutes($merged['opens_at'] ?? null);
        $closesAt = DayOff::overnightClose($opensAt, DayOff::closingClockToMinutes($merged['closes_at'] ?? null));

        if ($opensAt === null && $closesAt === null) {
            return true;
        }

        foreach ($sibling->blockedRanges() as [$start, $end]) {
            $beforeOpening = $opensAt !== null && $opensAt > 0 && $end <= $opensAt;
            $afterClosing = $closesAt !== null && $start >= $closesAt;

            if (! $beforeOpening && ! $afterClosing) {
                return false;
            }
        }

        return true;
    }

    private function olderSiblingsOf(DayOff $current, array $merged): \Illuminate\Support\Collection
    {
        $targets = $this->targetsOf($merged);

        return DayOff::where('location_id', $merged['location_id'])
            ->where('id', '!=', $current->id)
            ->whereDate('date', $merged['date'])
            ->where('is_recurring', (bool) $merged['is_recurring'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (DayOff $sibling) => $sibling->block_type === DayOff::BLOCK_LEGACY_CLOSURE
                && $this->targetsOf($sibling->snapshot()) == $targets)
            ->values();
    }

    private function sharesItemsAcrossScopes(array $candidate, DayOff $existing): bool
    {
        $candidateLocationWide = collect(self::TARGETS)->every(
            fn (array $target) => empty($candidate[$target[0]] ?? null)
        );

        if ($candidateLocationWide || $existing->isLocationWide()) {
            return true;
        }

        $roomsOf = fn (?array $packageIds) => $packageIds
            ? DB::table('package_rooms')->whereIn('package_id', $packageIds)->pluck('room_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $candidateRooms = DayOff::normalizedIds($candidate['room_ids'] ?? null) ?? [];
        $existingRooms = DayOff::normalizedIds($existing->room_ids) ?? [];

        return array_intersect($roomsOf(DayOff::normalizedIds($candidate['package_ids'] ?? null)), $existingRooms) !== []
            || array_intersect($roomsOf(DayOff::normalizedIds($existing->package_ids)), $candidateRooms) !== [];
    }

    private function splitsAroundOlderClosure(array $candidate, DayOff $existing): bool
    {
        $closedRange = $existing->closedRangeMinutes();
        $opensAt = DayOff::clockToMinutes($candidate['opens_at'] ?? null);
        $closesAt = DayOff::overnightClose($opensAt, DayOff::closingClockToMinutes($candidate['closes_at'] ?? null));

        if ($closedRange === null || ($opensAt === null && $closesAt === null)) {
            return false;
        }

        if (! $this->sharesItemsAcrossScopes($candidate, $existing)) {
            return false;
        }

        return ($opensAt ?? 0) < $closedRange[0] && ($closesAt ?? DayOff::MINUTES_PER_DAY) > $closedRange[1];
    }

    private function sharedItem(array $candidate, DayOff $existing): ?string
    {
        $candidateLocationWide = collect(self::TARGETS)->every(
            fn (array $target) => empty($candidate[$target[0]] ?? null)
        );

        if ($candidateLocationWide) {
            return $existing->isLocationWide() ? 'The entire location' : null;
        }

        foreach (self::TARGETS as [$column, $model, $singular, $plural]) {
            $shared = array_values(array_intersect(
                DayOff::normalizedIds($candidate[$column] ?? null) ?? [],
                DayOff::normalizedIds($existing->{$column}) ?? []
            ));

            if ($shared === []) {
                continue;
            }

            $names = $model::query()->withoutGlobalScopes()->whereIn('id', $shared)->pluck('name')->all();
            $label = $names === [] ? implode(', ', $shared) : implode(', ', $names);

            return ucfirst(count($shared) === 1 ? $singular : $plural) . ' "' . $label . '"';
        }

        return null;
    }

    private function presentWithRecord($dayOffs): array
    {
        $models = collect($dayOffs)->values();
        $presented = $this->presenter->presentMany($models);

        return $models->map(function (DayOff $dayOff, int $index) use ($presented) {
            return $presented[$index] + [
                'created_at' => $dayOff->created_at?->toIso8601String(),
                'updated_at' => $dayOff->updated_at?->toIso8601String(),
                'location' => $presented[$index]['location_name'] !== null
                    ? ['id' => (int) $dayOff->location_id, 'name' => $presented[$index]['location_name']]
                    : null,
            ];
        })->all();
    }

    private function visibleLocationIds(Request $request): array
    {
        $user = $this->resolveAuthUser($request);

        if (! $user instanceof User || ! $user->company_id || ! in_array((string) $user->role, EnsureStaff::ROLES, true)) {
            return [];
        }

        if (in_array(EnsureStaff::effectiveRole($user), ['location_manager', 'attendant'], true)) {
            return $user->location_id ? [(int) $user->location_id] : [];
        }

        return Location::query()
            ->where('company_id', $user->company_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function outsideManagedLocations(Request $request, int $locationId): ?JsonResponse
    {
        if (in_array($locationId, $this->visibleLocationIds($request), true)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden: you can only change blocks for the location you manage.',
        ], 403);
    }

    private function staffActor(Request $request): ?User
    {
        $user = $request->user('sanctum') ?? $request->user();

        return $user instanceof User ? $user : null;
    }

    private function actorMetadata(?User $actor): array
    {
        return [
            'user_id' => $actor?->id,
            'name' => $actor ? ActivityLog::describeActor($actor) : null,
            'email' => $actor?->email,
            'role' => $actor?->role,
        ];
    }

    private function summarize(string $verb, DayOff $dayOff): string
    {
        $presented = $this->presenter->presentOne($dayOff);
        $when = Carbon::parse($dayOff->date)->format('D, M j, Y') . ($dayOff->is_recurring ? ' (every year)' : '');
        $group = $dayOff->group_name ? " for {$dayOff->group_name}" : '';

        return "{$verb} {$presented['scope_label']} on {$when}: {$presented['window_label']}{$group}";
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    private function staffMaySeeDetails($user, int $locationId): bool
    {
        if (! $user instanceof User || ! $user->company_id || ! in_array((string) $user->role, EnsureStaff::ROLES, true)) {
            return false;
        }

        $companyId = Location::query()->whereKey($locationId)->value('company_id');

        if ($companyId === null || (int) $companyId !== (int) $user->company_id) {
            return false;
        }

        if (in_array(EnsureStaff::effectiveRole($user), ['location_manager', 'attendant'], true)) {
            return (int) $user->location_id === $locationId || $user->canWorkAt($locationId);
        }

        return true;
    }

    private function goneResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'That block has already been removed.',
        ], 404);
    }

    private function changedElsewhereResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Someone else changed this block a moment ago. Reload it and try again.',
        ], 409);
    }

    private function unavailableUntilMigrated(): ?JsonResponse
    {
        if (DayOff::tracksAvailabilityDetails()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Blocking dates needs a database update that has not been applied yet. Please ask an administrator to run the latest migrations.',
        ], 503);
    }
}
