<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\CustomField;
use App\Services\CustomFieldService;
use App\Support\CacheGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    use ScopesByAuthUser;

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'label' => "{$required}|string|max:255",
            'type' => ['sometimes', Rule::in([CustomField::TYPE_CHECKBOX])],
            'help_text' => 'nullable|string|max:255',
            'is_required' => 'sometimes|boolean',
            'audience' => ['sometimes', Rule::in(CustomField::AUDIENCES)],
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|exists:locations,id',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveAuthUser($request);

        // Never list without a company to scope by — a null company used to mean "no
        // filter", which handed the caller every tenant's questions.
        if (!$user?->company_id) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $query = CustomField::query()->where(function ($q) use ($user) {
            $q->whereNull('company_id')->orWhere('company_id', $user->company_id);
        });

        // A manager or attendant only runs one venue, so they list what applies there.
        if ($user && in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $query->forLocation($user->location_id);
        }

        if ($request->filled('audience')) {
            $query->forAudience($request->string('audience')->toString());
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $fields = $query->orderBy('display_order')->orderBy('id')->get()
            ->map(function (CustomField $field) use ($request) {
                $field->can_manage = $this->canManage($request, $field);

                return $field;
            });

        return response()->json(['success' => true, 'data' => $fields]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(true));
        $user = $this->resolveAuthUser($request);

        if (!$user?->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not linked to a company, so this question has nowhere to live.',
            ], 403);
        }

        if ($guard = $this->deniesTargeting($request, $user, $validated)) {
            return $guard;
        }

        // A manager runs one venue: an unscoped question from them would otherwise ask
        // every venue in the company.
        if ($this->isSingleVenueRole($user) && empty($validated['location_ids'])) {
            $validated['location_ids'] = [$user->location_id];
        }

        $field = CustomField::create(array_merge($validated, [
            'company_id' => $user->company_id,
            'type' => $validated['type'] ?? CustomField::TYPE_CHECKBOX,
            'location_ids' => CustomField::normalizeIds($validated['location_ids'] ?? null),
            'package_ids' => CustomField::normalizeIds($validated['package_ids'] ?? null),
            'attraction_ids' => CustomField::normalizeIds($validated['attraction_ids'] ?? null),
            'event_ids' => CustomField::normalizeIds($validated['event_ids'] ?? null),
        ]));

        CacheGroups::flush([CacheGroups::CUSTOM_FIELDS]);

        return response()->json(['success' => true, 'data' => $field], 201);
    }

    public function show(Request $request, CustomField $customField): JsonResponse
    {
        if ($guard = $this->denies($request, $customField)) {
            return $guard;
        }

        return response()->json(['success' => true, 'data' => $customField]);
    }

    public function update(Request $request, CustomField $customField): JsonResponse
    {
        if ($guard = $this->deniesWrite($request, $customField)) {
            return $guard;
        }

        $validated = $request->validate($this->rules(false));
        $user = $this->resolveAuthUser($request);

        if ($guard = $this->deniesTargeting($request, $user, $validated)) {
            return $guard;
        }

        // Same reason as store(): clearing the venue list would otherwise promote a
        // manager's question to all ten venues.
        if ($this->isSingleVenueRole($user) && $request->has('location_ids') && empty($validated['location_ids'])) {
            $validated['location_ids'] = [$user->location_id];
        }

        foreach (['location_ids', 'package_ids', 'attraction_ids', 'event_ids'] as $key) {
            if ($request->has($key)) {
                $validated[$key] = CustomField::normalizeIds($validated[$key] ?? null);
            }
        }

        $customField->update($validated);
        CacheGroups::flush([CacheGroups::CUSTOM_FIELDS]);

        return response()->json(['success' => true, 'data' => $customField->fresh()]);
    }

    public function destroy(Request $request, CustomField $customField): JsonResponse
    {
        if ($guard = $this->deniesWrite($request, $customField)) {
            return $guard;
        }

        $customField->delete();
        CacheGroups::flush([CacheGroups::CUSTOM_FIELDS]);

        return response()->json(['success' => true, 'message' => 'Custom field removed']);
    }

    /**
     * What a purchase page should show. Public because the storefront asks it before a
     * guest has any session, and it returns nothing but the questions themselves.
     */
    public function applicable(Request $request, CustomFieldService $service): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required_without:items', Rule::in(['package', 'attraction', 'event'])],
            'item_id' => 'required_without:items|integer|min:1',
            // A whole cart in one call: "attraction:8,event:7". One request per line would
            // otherwise burn a guest's rate-limit allowance before they could pay.
            'items' => 'required_without:item_type|string|max:1200',
        ]);

        if (!empty($validated['items'])) {
            return response()->json([
                'success' => true,
                'data' => $this->present($service->applicableForMany(
                    $this->parseItems($validated['items']),
                    $service->audienceFor($request->user('sanctum')),
                )),
            ]);
        }

        // Scope comes from the item itself, never from the caller: a guest could otherwise
        // pass someone else's location id and read another venue's questions.
        [$locationId, $companyId] = $service->resolveScope(
            $validated['item_type'],
            (int) $validated['item_id'],
        );

        $fields = $locationId
            ? $service->applicableFor(
                $validated['item_type'],
                (int) $validated['item_id'],
                $companyId,
                $locationId,
                // Taken from who is asking: a guest could otherwise request the staff
                // audience and read questions only staff are meant to see.
                $service->audienceFor($request->user('sanctum')),
            )
            : collect();

        return response()->json(['success' => true, 'data' => $this->present($fields)]);
    }

    /** @param \Illuminate\Support\Collection<int, CustomField> $fields */
    private function present($fields): array
    {
        return $fields->map(fn (CustomField $field) => [
            'id' => $field->id,
            'label' => $field->label,
            'type' => $field->type,
            'help_text' => $field->help_text,
            'is_required' => $field->is_required,
        ])->values()->all();
    }

    /**
     * "attraction:8,event:7" -> [['type' => 'attraction', 'id' => 8], ...]
     *
     * @return array<int, array{type: string, id: int}>
     */
    private function parseItems(string $raw): array
    {
        $items = [];

        foreach (array_slice(explode(',', $raw), 0, 50) as $chunk) {
            [$type, $id] = array_pad(explode(':', trim($chunk), 2), 2, null);

            if (!in_array($type, ['package', 'attraction', 'event'], true) || !ctype_digit((string) $id)) {
                continue;
            }

            $items[] = ['type' => $type, 'id' => (int) $id];
        }

        return $items;
    }

    private function denies(Request $request, CustomField $field): ?JsonResponse
    {
        $user = $this->resolveAuthUser($request);
        $missing = response()->json(['success' => false, 'message' => 'Custom field not found'], 404);

        if (!$user?->company_id) {
            return $missing;
        }

        // A field with no company applies to every company, so a tenant-scoped account
        // must not be able to edit or delete it.
        if ($field->company_id === null || (int) $field->company_id !== (int) $user->company_id) {
            return $missing;
        }

        if ($this->isSingleVenueRole($user) && !$field->appliesToLocation((int) $user->location_id)) {
            return $missing;
        }

        return null;
    }

    /**
     * Reading a question that applies here is fine; changing it is not, unless this venue
     * is the only one it covers. Otherwise one venue's manager could rename or delete a
     * question the other nine rely on.
     */
    private function deniesWrite(Request $request, CustomField $field): ?JsonResponse
    {
        if ($guard = $this->denies($request, $field)) {
            return $guard;
        }

        $user = $this->resolveAuthUser($request);

        if (!$this->isSingleVenueRole($user)) {
            return null;
        }

        $scoped = array_map('intval', $field->location_ids ?? []);

        if ($scoped !== [(int) $user->location_id]) {
            return response()->json([
                'success' => false,
                'message' => 'This question covers more than your venue, so only a company admin can change it.',
            ], 403);
        }

        return null;
    }

    /** Whether the caller is allowed to change this one, for the admin list to act on. */
    private function canManage(Request $request, CustomField $field): bool
    {
        return $this->deniesWrite($request, $field) === null;
    }

    private function isSingleVenueRole(?\App\Models\User $user): bool
    {
        return $user
            && in_array((string) $user->role, ['location_manager', 'attendant'], true)
            && $user->location_id !== null;
    }

    /**
     * Keeps targeting inside the caller's own company, and inside their own venue when
     * they only run one. Ids are validated to exist, not to belong to the caller.
     */
    private function deniesTargeting(Request $request, ?\App\Models\User $user, array $validated): ?JsonResponse
    {
        if (!$user?->company_id) {
            return null;
        }

        $companyLocationIds = \App\Models\Location::where('company_id', $user->company_id)->pluck('id')->all();
        $allowedLocationIds = $this->isSingleVenueRole($user) ? [(int) $user->location_id] : $companyLocationIds;

        foreach ((array) ($validated['location_ids'] ?? []) as $locationId) {
            if (!in_array((int) $locationId, array_map('intval', $allowedLocationIds), true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One of the chosen venues is not yours to target.',
                ], 403);
            }
        }

        $itemChecks = [
            'package_ids' => \App\Models\Package::class,
            'attraction_ids' => \App\Models\Attraction::class,
            'event_ids' => \App\Models\Event::class,
        ];

        foreach ($itemChecks as $key => $model) {
            $ids = array_map('intval', (array) ($validated[$key] ?? []));

            if (empty($ids)) {
                continue;
            }

            $outside = $model::whereIn('id', $ids)
                ->whereNotIn('location_id', $allowedLocationIds)
                ->exists();

            if ($outside) {
                return response()->json([
                    'success' => false,
                    'message' => 'One of the chosen items belongs to a venue that is not yours.',
                ], 403);
            }
        }

        return null;
    }
}
