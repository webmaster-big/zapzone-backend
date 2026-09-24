<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Promo;
use App\Services\DiscountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromoController extends Controller
{
    use ScopesByAuthUser;
    use RecordsPageAnalytics;

    private const MULTI_LOCATION_ROLES = ['company_admin', 'admin'];

    public function index(Request $request): JsonResponse
    {
        $query = Promo::with(['creator']);

        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $locationId = $authUser->location_id;
                $query->where(function ($q) use ($locationId) {
                    $q->whereNull('location_ids')
                      ->orWhereJsonContains('location_ids', (int) $locationId)
                      ->orWhereJsonContains('location_ids', (string) $locationId)
                      ->orWhereHas('creator', fn($u) => $u->where('location_id', $locationId));
                });
            } elseif ($authUser->company_id) {
                $companyId = $authUser->company_id;
                $companyLocationIds = Location::where('company_id', $companyId)->pluck('id')->all();
                $query->where(function ($q) use ($companyId, $companyLocationIds) {
                    $q->whereNull('location_ids')
                      ->orWhereHas('creator', fn($u) => $u->where('company_id', $companyId));
                    foreach ($companyLocationIds as $locationId) {
                        $q->orWhereJsonContains('location_ids', (int) $locationId)
                          ->orWhereJsonContains('location_ids', (string) $locationId);
                    }
                });
            }
        }

        if ($request->filled('location_id')) {
            $query->forLocation($request->location_id);
        }

        if ($request->has('status')) {
            if ($request->status !== 'all') {
                $query->where('status', $request->status);
            }

            $query->where('deleted', false);
        } else {
            $query->active();
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('only_valid')) {
            if ($request->boolean('only_valid')) {
                $query->valid();
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['code', 'name', 'type', 'value', 'start_date', 'end_date', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $promos = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'promos' => $promos->items(),
                'pagination' => [
                    'current_page' => $promos->currentPage(),
                    'last_page' => $promos->lastPage(),
                    'per_page' => $promos->perPage(),
                    'total' => $promos->total(),
                    'from' => $promos->firstItem(),
                    'to' => $promos->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:255'],
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'description' => 'nullable|string',
            'created_by' => 'sometimes|nullable|integer',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|exists:locations,id',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        if ($validated['type'] === 'percentage' && (float) $validated['value'] > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        foreach (['location_ids', 'package_ids', 'attraction_ids', 'event_ids'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = Promo::normalizeIds($validated[$field]);
            }
        }

        if (isset($validated['code'])) {
            $validated['code'] = trim($validated['code']);
        }

        if (empty($validated['code'])) {
            do {
                $validated['code'] = 'PROMO' . strtoupper(Str::random(6));
            } while (Promo::where('code', $validated['code'])->exists());
        }

        $validated['location_ids'] = $this->locationIdsForActor($request, $validated['location_ids'] ?? null);
        $validated['created_by'] = $this->resolveAuthUser($request)?->id ?? ($validated['created_by'] ?? null);

        foreach (['package_ids', 'attraction_ids', 'event_ids'] as $axis) {
            $validated[$axis] = $validated[$axis] ?? null;
        }

        if (!array_key_exists('usage_limit_per_user', $validated) || $validated['usage_limit_per_user'] === null) {
            $validated['usage_limit_per_user'] = 1;
        }

        $freedFrom = null;

        $promo = DB::transaction(function () use ($request, $validated, &$freedFrom) {
            $this->assertCodeIsFree($request, $validated['code'], null, $validated['location_ids'] ?? null);
            $freedFrom = $this->freeRetiredCode($request, $validated['code']);

            return Promo::create($validated);
        });

        ActivityLog::log(
            action: 'Promo Created',
            category: 'create',
            description: "Promo code '{$promo->code}' was created",
            userId: $this->resolveAuthUser($request)?->id,
            locationId: $this->promoLogLocation($promo),
            entityType: 'promo',
            entityId: $promo->id,
            metadata: array_merge([
                'created_by' => $this->actorMeta($request),
                'promo_details' => $this->promoSnapshot($promo),
                'targeting' => $this->targetingSnapshot($promo),
            ], $freedFrom === null ? [] : ['reused_code_retired_from_promo_id' => $freedFrom])
        );

        $promo->load(['creator']);

        return response()->json([
            'success' => true,
            'message' => 'Promo created successfully',
            'data' => $promo,
        ], 201);
    }

    protected function actorMeta(Request $request): array
    {
        $user = $this->resolveAuthUser($request);

        return [
            'user_id' => $user?->id,
            'name' => $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : null,
            'email' => $user?->email,
            'role' => $user?->role,
        ];
    }

    protected function promoSnapshot(Promo $promo): array
    {
        return [
            'code' => $promo->code,
            'name' => $promo->name,
            'type' => $promo->type,
            'value' => (string) $promo->value,
            'start_date' => $promo->start_date?->toDateString(),
            'end_date' => $promo->end_date?->toDateString(),
            'usage_limit_total' => $promo->usage_limit_total,
            'usage_limit_per_user' => $promo->usage_limit_per_user,
            'status' => $promo->status,
        ];
    }

    protected function targetingSnapshot(Promo $promo): array
    {
        return [
            'location_ids' => Promo::normalizeIds($promo->location_ids),
            'package_ids' => Promo::normalizeIds($promo->package_ids),
            'attraction_ids' => Promo::normalizeIds($promo->attraction_ids),
            'event_ids' => Promo::normalizeIds($promo->event_ids),
        ];
    }

    protected function promoLogLocation(Promo $promo): ?int
    {
        $targets = Promo::normalizeIds($promo->location_ids);

        return $targets !== null && count($targets) === 1 ? $targets[0] : null;
    }

    protected function assertCodeIsFree(Request $request, string $code, ?int $ignoreId = null, ?array $locationIds = null): void
    {
        $conflict = Promo::where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if (!$conflict || $conflict->deleted) {
            return;
        }

        $user = $this->resolveAuthUser($request);
        $theirs = $user === null
            || $user->company_id === null
            || $this->promoBelongsToCompany($conflict, (int) $user->company_id);

        $suggestion = $this->suggestFreeCode($request, $code, $locationIds);

        if (!$theirs) {
            $this->refuseCode(
                sprintf('That code is already in use elsewhere. Try %s instead, or pick your own.', $suggestion),
                $suggestion,
                null
            );
        }

        if ($user && !in_array((string) $user->role, self::MULTI_LOCATION_ROLES, true)) {
            $own = $user->location_id ? (int) $user->location_id : null;
            $targets = Promo::normalizeIds($conflict->location_ids);
            $coversTheirVenue = $targets === null || ($own !== null && in_array($own, $targets, true));

            if (!$coversTheirVenue) {
                $this->refuseCode(
                    sprintf('That code is in use at another location. Try %s instead, or pick your own.', $suggestion),
                    $suggestion,
                    null
                );
            }
        }

        $this->refuseCode(
            sprintf(
                'That code is already used by "%s" (%s%s) at %s. Try %s instead, or edit that promo.',
                $conflict->name,
                $conflict->status,
                $conflict->batch_id ? ', part of a bulk batch' : '',
                $this->describeTargets($conflict),
                $suggestion
            ),
            $suggestion,
            [
                'id' => $conflict->id,
                'name' => $conflict->name,
                'status' => $conflict->status,
                'locations' => $this->describeTargets($conflict),
                'in_batch' => $conflict->batch_id !== null,
            ]
        );
    }

    protected function refuseCode(string $message, string $suggestion, ?array $conflict): void
    {
        $exception = ValidationException::withMessages(['code' => [$message]]);

        $exception->response = response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['code' => [$message]],
            'suggested_code' => $suggestion,
            'conflict' => $conflict,
        ], 422);

        throw $exception;
    }

    protected function suggestFreeCode(Request $request, string $code, ?array $locationIds = null): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $code) ?: 'PROMO');
        $user = $this->resolveAuthUser($request);
        $targets = Promo::normalizeIds($locationIds);
        $hintLocationId = $targets[0] ?? $user?->location_id;
        $locationName = $hintLocationId ? Location::find($hintLocationId)?->name : null;

        $hint = $locationName
            ? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', strtok($locationName, ' ')) ?: '', 0, 12))
            : '';

        $candidates = $hint !== '' ? [$base . '-' . $hint] : [];

        for ($i = 2; $i <= 99; $i++) {
            $candidates[] = $base . '-' . $i;
        }

        foreach ($candidates as $candidate) {
            $candidate = substr($candidate, 0, 255);

            if (!Promo::where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $base . '-' . strtoupper(Str::random(4));
    }

    protected function describeTargets(Promo $promo): string
    {
        $targets = Promo::normalizeIds($promo->location_ids);

        if ($targets === null) {
            return 'all locations';
        }

        $names = Location::whereIn('id', $targets)->orderBy('name')->pluck('name')->all();

        return $names === [] ? 'no location' : implode(', ', $names);
    }

    protected function freeRetiredCode(Request $request, string $code, ?int $ignoreId = null): ?int
    {
        $retired = Promo::where('code', $code)
            ->where('deleted', true)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->lockForUpdate()
            ->first();

        if (!$retired) {
            return null;
        }

        $this->assertCanManage($request, $retired);

        $user = $this->resolveAuthUser($request);
        $sameCompany = $user === null
            || $user->company_id === null
            || $this->promoBelongsToCompany($retired, (int) $user->company_id);

        if (!$sameCompany) {
            throw ValidationException::withMessages([
                'code' => ['That code is not available. Please choose another one.'],
            ]);
        }

        $base = substr($code, 0, 200) . '-retired-' . $retired->id;
        $freed = $base;
        $attempt = 1;

        while (Promo::where('code', $freed)->whereKeyNot($retired->id)->exists()) {
            $freed = $base . '-' . $attempt;
            $attempt++;
        }

        $retired->forceFill(['code' => $freed])->save();

        ActivityLog::log(
            action: 'Promo Code Freed',
            category: 'update',
            description: "Deleted promo '{$code}' was renamed to '{$freed}' so the code could be issued again",
            userId: $this->resolveAuthUser($request)?->id,
            locationId: $this->promoLogLocation($retired),
            entityType: 'promo',
            entityId: $retired->id,
            metadata: [
                'freed_by' => $this->actorMeta($request),
                'previous_code' => $code,
                'new_code' => $freed,
            ]
        );

        return $retired->id;
    }

    protected function promoBelongsToCompany(Promo $promo, int $companyId): bool
    {
        $creatorCompanyId = $promo->creator?->company_id;

        if ($creatorCompanyId !== null) {
            return (int) $creatorCompanyId === $companyId;
        }

        $targets = Promo::normalizeIds($promo->location_ids);

        if ($targets === null) {
            return false;
        }

        $companyLocationIds = Location::where('company_id', $companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_diff($targets, $companyLocationIds) === [];
    }

    protected function locationIdsForActor(Request $request, ?array $requested): ?array
    {
        $requested = Promo::normalizeIds($requested);
        $user = $this->resolveAuthUser($request);

        if (!$user) {
            return $requested;
        }

        if (!in_array((string) $user->role, self::MULTI_LOCATION_ROLES, true)) {
            $own = $user->location_id ? (int) $user->location_id : null;

            if ($own === null) {
                throw ValidationException::withMessages([
                    'location_ids' => ['Your account is not assigned to a location, so it cannot create promo codes. Please ask an administrator.'],
                ]);
            }

            if ($requested !== null && $requested !== [$own]) {
                $name = Location::find($own)?->name ?? 'your location';

                throw ValidationException::withMessages([
                    'location_ids' => ["You can only create promo codes for {$name}. Ask a company admin for a code that covers other locations."],
                ]);
            }

            return [$own];
        }

        if ($requested !== null && $user->company_id) {
            $allowed = Location::where('company_id', $user->company_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $outside = array_diff($requested, $allowed);

            if ($outside !== []) {
                throw ValidationException::withMessages([
                    'location_ids' => ['One of those locations does not belong to your company.'],
                ]);
            }
        }

        return $requested;
    }

    protected function assertCanManageBatch(Request $request, string $batchId): void
    {
        foreach (Promo::where('batch_id', $batchId)->get() as $promo) {
            $this->assertCanManage($request, $promo);
        }
    }

    protected function encodeIds(?array $ids): ?string
    {
        $normalized = Promo::normalizeIds($ids);

        return $normalized === null ? null : json_encode($normalized);
    }

    protected function assertCanManage(Request $request, Promo $promo): void
    {
        $user = $this->resolveAuthUser($request);

        if (!$user || in_array((string) $user->role, self::MULTI_LOCATION_ROLES, true)) {
            return;
        }

        $own = $user->location_id ? (int) $user->location_id : null;
        $target = Promo::normalizeIds($promo->location_ids);
        $theirs = $promo->created_by !== null && (int) $promo->created_by === (int) $user->id;
        $appliesHere = $target === null || ($own !== null && in_array($own, $target, true));

        if (!$theirs && !$appliesHere) {
            throw ValidationException::withMessages([
                'location_ids' => ['This promo code belongs to another location, so only a company admin can change it.'],
            ]);
        }
    }

    public function show(Promo $promo): JsonResponse
    {
        $promo->load(['creator']);

        return response()->json([
            'success' => true,
            'data' => $promo,
        ]);
    }

    public function update(Request $request, Promo $promo): JsonResponse
    {
        $this->assertCanManage($request, $promo);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:255'],
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'value' => 'sometimes|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'usage_limit_total' => 'sometimes|nullable|integer|min:1',
            'usage_limit_per_user' => 'sometimes|integer|min:1',
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'expired', 'exhausted'])],
            'description' => 'sometimes|nullable|string',
            'location_ids' => 'sometimes|nullable|array',
            'location_ids.*' => 'integer|exists:locations,id',
            'package_ids' => 'sometimes|nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'attraction_ids' => 'sometimes|nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'sometimes|nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        if (($validated['type'] ?? $promo->type) === 'percentage'
            && (float) ($validated['value'] ?? $promo->value) > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        if (array_key_exists('code', $validated)) {
            $validated['code'] = trim($validated['code']);
            $this->assertCodeIsFree($request, $validated['code'], $promo->id, $validated['location_ids'] ?? $promo->location_ids);
        }

        foreach (['location_ids', 'package_ids', 'attraction_ids', 'event_ids'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = Promo::normalizeIds($validated[$field]);
            }
        }

        if (array_key_exists('location_ids', $validated)) {
            $user = $this->resolveAuthUser($request);

            if ($user && !in_array((string) $user->role, self::MULTI_LOCATION_ROLES, true)) {
                unset($validated['location_ids']);
            } else {
                $validated['location_ids'] = $this->locationIdsForActor($request, $validated['location_ids']);
            }
        }

        if (array_key_exists('usage_limit_per_user', $validated) && $validated['usage_limit_per_user'] === null) {
            unset($validated['usage_limit_per_user']);
        }

        $before = $this->promoSnapshot($promo) + $this->targetingSnapshot($promo);

        DB::transaction(function () use ($request, $validated, $promo) {
            if (array_key_exists('code', $validated)) {
                $this->assertCodeIsFree(
                    $request,
                    $validated['code'],
                    $promo->id,
                    $validated['location_ids'] ?? $promo->location_ids
                );
                $this->freeRetiredCode($request, $validated['code'], $promo->id);
            }

            $promo->update($validated);
        });

        $promo->refresh();
        $after = $this->promoSnapshot($promo) + $this->targetingSnapshot($promo);
        $changed = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changed[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        ActivityLog::log(
            action: 'Promo Updated',
            category: 'update',
            description: $changed === []
                ? "Promo code '{$promo->code}' was saved with no changes"
                : sprintf("Promo code '%s' was updated (%s)", $promo->code, implode(', ', array_keys($changed))),
            userId: $this->resolveAuthUser($request)?->id,
            locationId: $this->promoLogLocation($promo),
            entityType: 'promo',
            entityId: $promo->id,
            metadata: [
                'updated_by' => $this->actorMeta($request),
                'changes' => $changed,
            ]
        );

        $promo->load(['creator']);

        return response()->json([
            'success' => true,
            'message' => 'Promo updated successfully',
            'data' => $promo,
        ]);
    }

    public function destroy(Request $request, Promo $promo): JsonResponse
    {
        $this->assertCanManage($request, $promo);

        $promoCode = $promo->code;
        $promoId = $promo->id;

        $promo->update(['deleted' => true, 'status' => 'inactive']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Promo Deleted',
            category: 'delete',
            description: "Promo code '{$promoCode}' was deleted",
            userId: auth()->id(),
            locationId: null,
            entityType: 'promo',
            entityId: $promoId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'promo_details' => [
                    'promo_id' => $promoId,
                    'code' => $promoCode,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Promo deleted successfully',
        ]);
    }

    public function validateByCode(Request $request, DiscountService $discounts): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'location_id' => 'nullable|integer',
            'subtotal' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.type' => 'required_with:items|string',
            'items.*.id' => 'required_with:items|integer',
            'customer_id' => 'nullable|integer',
        ]);

        $result = $discounts->validatePromo($request->code, $this->buildContext($request));

        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['reason'],
                'data' => [
                    'is_valid' => false,
                    'promo' => $result['promo'] ?? null,
                ],
            ]);
        }

        $promo = $result['promo'];

        return response()->json([
            'success' => true,
            'data' => [
                'is_valid' => true,
                'promo' => $promo,
                'discount_amount' => $result['discount_amount'],
                'discount_type' => $result['discount_type'],
                'eligible_subtotal' => $result['eligible_subtotal'],
                'applied_discount' => $result['entry'],
                'expired' => $promo->isExpired(),
                'started' => $promo->hasStarted(),
                'usage_remaining' => $promo->usage_limit_total
                    ? max(0, $promo->usage_limit_total - $promo->current_usage)
                    : null,
            ],
        ]);
    }

    public function apply(Request $request, Promo $promo, DiscountService $discounts): JsonResponse
    {
        $result = $discounts->validatePromo($promo->code, $this->buildContext($request));

        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['reason'],
            ], 400);
        }

        $discounts->applyPromo($promo, $result['discount_amount'], $request);

        return response()->json([
            'success' => true,
            'message' => 'Promo code applied successfully',
            'data' => [
                'promo' => $promo->fresh(),
                'discount_amount' => $result['discount_amount'],
                'discount_type' => $result['discount_type'],
                'applied_discount' => $result['entry'],
            ],
        ]);
    }

    private function buildContext(Request $request): array
    {
        return [
            'location_id' => $request->input('location_id'),
            'subtotal' => (float) $request->input('subtotal', 0),
            'items' => $request->input('items', []),
            'customer_id' => $request->input('customer_id'),
        ];
    }

    public function getValid(Request $request): JsonResponse
    {
        $promos = Promo::valid()
            ->orderBy('end_date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promos,
        ]);
    }

    public function toggleStatus(Request $request, Promo $promo): JsonResponse
    {
        $this->assertCanManage($request, $promo);

        $previousStatus = $promo->status;
        $newStatus = $promo->status === 'active' ? 'inactive' : 'active';
        $promo->update(['status' => $newStatus]);

        ActivityLog::log(
            action: $newStatus === 'active' ? 'Promo Activated' : 'Promo Deactivated',
            category: 'update',
            description: sprintf("Promo code '%s' was %s", $promo->code, $newStatus === 'active' ? 'activated' : 'deactivated'),
            userId: $this->resolveAuthUser($request)?->id,
            locationId: $this->promoLogLocation($promo),
            entityType: 'promo',
            entityId: $promo->id,
            metadata: [
                'changed_by' => $this->actorMeta($request),
                'status' => ['from' => $previousStatus, 'to' => $newStatus],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Promo status updated successfully',
            'data' => $promo,
        ]);
    }

    public function generateBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'description' => 'nullable|string',
            'created_by' => 'sometimes|nullable|integer',
            'quantity' => 'required|integer|min:1|max:1000',
            'code_prefix' => 'nullable|string|max:10|alpha_num',
            'code_length' => 'nullable|integer|min:4|max:16',
            'usage_limit_per_code' => 'nullable|integer|min:1',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|exists:locations,id',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        $locationIds = $this->locationIdsForActor($request, $validated['location_ids'] ?? null);
        $createdBy = $this->resolveAuthUser($request)?->id ?? ($validated['created_by'] ?? null);

        if ($validated['type'] === 'percentage' && (float) $validated['value'] > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        $quantity = $validated['quantity'];
        $prefix = strtoupper($validated['code_prefix'] ?? 'ZAP');
        $codeLength = $validated['code_length'] ?? 8;
        $batchId = (string) Str::uuid();

        $codes = [];
        $existingCodes = Promo::pluck('code')->flip();
        $attempts = 0;
        $maxAttempts = $quantity * 10;

        while (count($codes) < $quantity && $attempts < $maxAttempts) {
            $attempts++;
            $randomPart = strtoupper(Str::random(max(1, $codeLength - strlen($prefix))));
            $code = $prefix . $randomPart;

            if (!isset($existingCodes[$code]) && !isset($codes[$code])) {
                $codes[$code] = true;
            }
        }

        if (count($codes) < $quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Could not generate enough unique codes. Try a longer code length or different prefix.',
            ], 422);
        }

        $promos = [];
        $now = now();

        foreach (array_keys($codes) as $code) {
            $promos[] = [
                'code' => $code,
                'code_mode' => 'unique',
                'batch_id' => $batchId,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'usage_limit_total' => $validated['usage_limit_per_code'] ?? 1,
                'usage_limit_per_user' => 1,
                'current_usage' => 0,
                'status' => 'active',
                'description' => $validated['description'] ?? null,
                'created_by' => $createdBy,
                'deleted' => false,
                'location_ids' => $locationIds === null ? null : json_encode($locationIds),
                'package_ids' => $this->encodeIds($validated['package_ids'] ?? null),
                'attraction_ids' => $this->encodeIds($validated['attraction_ids'] ?? null),
                'event_ids' => $this->encodeIds($validated['event_ids'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($promos, 100) as $chunk) {
            Promo::insert($chunk);
        }

        $createdPromos = Promo::where('batch_id', $batchId)->get();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Promo Generated',
            category: 'create',
            description: "Generated {$quantity} unique promo codes (batch: {$batchId})",
            userId: auth()->id(),
            locationId: null,
            entityType: 'promo',
            entityId: null,
            metadata: [
                'generated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'prefix' => $prefix,
                'promo_name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$quantity} unique promo codes generated successfully",
            'data' => [
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'prefix' => $prefix,
                'sample_codes' => $createdPromos->take(5)->pluck('code'),
                'promo_name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
        ], 201);
    }

    public function listBatches(Request $request): JsonResponse
    {
        $batches = Promo::whereNotNull('batch_id')
            ->where('deleted', false)
            ->selectRaw('batch_id, name, type, value, start_date, end_date, MIN(created_at) as created_at, COUNT(*) as total_codes, SUM(current_usage) as total_used, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_codes, SUM(CASE WHEN status = "exhausted" THEN 1 ELSE 0 END) as exhausted_codes')
            ->groupBy('batch_id', 'name', 'type', 'value', 'start_date', 'end_date')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }

    public function showBatch(Request $request, string $batchId): JsonResponse
    {
        $promos = Promo::where('batch_id', $batchId)
            ->where('deleted', false);

        if ($request->has('status')) {
            $promos->where('status', $request->status);
        }

        if ($request->has('used')) {
            if ($request->boolean('used')) {
                $promos->where('current_usage', '>', 0);
            } else {
                $promos->where('current_usage', 0);
            }
        }

        $sortBy = $request->get('sort_by', 'code');
        $sortOrder = $request->get('sort_order', 'asc');
        if (in_array($sortBy, ['code', 'status', 'current_usage', 'created_at'])) {
            $promos->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 50);
        $result = $promos->paginate($perPage);

        $summary = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->selectRaw('COUNT(*) as total_codes, SUM(current_usage) as total_used, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_codes, SUM(CASE WHEN status = "exhausted" THEN 1 ELSE 0 END) as exhausted_codes, SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive_codes')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'batch_id' => $batchId,
                'summary' => $summary,
                'promos' => $result->items(),
                'pagination' => [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'from' => $result->firstItem(),
                    'to' => $result->lastItem(),
                ],
            ],
        ]);
    }

    public function exportBatchCsv(Request $request, string $batchId): StreamedResponse
    {
        $promos = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->orderBy('code')
            ->get();

        if ($promos->isEmpty()) {
            abort(404, 'Batch not found or has no codes');
        }

        $first = $promos->first();
        $fileName = 'promo_codes_' . Str::slug($first->name) . '_' . $batchId . '.csv';

        return response()->streamDownload(function () use ($promos) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Code',
                'Name',
                'Type',
                'Value',
                'Start Date',
                'End Date',
                'Status',
                'Usage Limit',
                'Current Usage',
                'Created At',
            ]);

            foreach ($promos as $promo) {
                fputcsv($handle, [
                    $promo->code,
                    $promo->name,
                    $promo->type,
                    $promo->value,
                    $promo->start_date->format('Y-m-d'),
                    $promo->end_date->format('Y-m-d'),
                    $promo->status,
                    $promo->usage_limit_total,
                    $promo->current_usage,
                    $promo->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function deactivateBatch(Request $request, string $batchId): JsonResponse
    {
        $this->assertCanManageBatch($request, $batchId);

        $count = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        ActivityLog::log(
            action: 'Bulk Promo Batch Deactivated',
            category: 'update',
            description: "Deactivated batch {$batchId} ({$count} codes)",
            userId: $this->resolveAuthUser($request)?->id,
            locationId: null,
            entityType: 'promo',
            entityId: null,
            metadata: [
                'deactivated_by' => $this->actorMeta($request),
                'batch_id' => $batchId,
                'deactivated_count' => $count,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} promo codes deactivated",
            'data' => ['deactivated_count' => $count],
        ]);
    }

    public function destroyBatch(Request $request, string $batchId): JsonResponse
    {
        $this->assertCanManageBatch($request, $batchId);

        $count = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->update(['deleted' => true, 'status' => 'inactive']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Promo Batch Deleted',
            category: 'delete',
            description: "Deleted batch {$batchId} ({$count} codes)",
            userId: auth()->id(),
            locationId: null,
            entityType: 'promo',
            entityId: null,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'batch_id' => $batchId,
                'deleted_count' => $count,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} promo codes deleted",
            'data' => ['deleted_count' => $count],
        ]);
    }
}
