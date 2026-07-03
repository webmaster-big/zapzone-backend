<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Attraction;
use App\Models\Event;
use App\Models\Package;
use App\Models\WaiverSetting;
use App\Models\WaiverTemplate;
use App\Services\WaiverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WaiverTemplateController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(private WaiverService $waivers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = WaiverTemplate::with(['location:id,name', 'creator:id,first_name,last_name']);
            $this->applyAuthScope($query, $request);

            if ($request->filled('status')) {
                $query->where('status', $request->string('status'));
            }
            if ($request->filled('search')) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                    ->orWhere('internal_description', 'like', "%{$search}%"));
            }

            $query->orderByDesc('updated_at');

            $templates = $query->paginate($request->integer('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => [
                    'waiver_templates' => $templates->items(),
                    'pagination' => $this->paginationMeta($templates),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to fetch waiver templates', $e);
        }
    }

    public function show(WaiverTemplate $waiverTemplate): JsonResponse
    {
        if (!$this->authorizeRecordScope($waiverTemplate)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => $waiverTemplate->load(['location:id,name', 'versions', 'creator:id,first_name,last_name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardCanManageTemplates($authUser)) {
            return $guard;
        }

        try {
            $validated = $this->validatePayload($request, true);

            $validated['company_id'] = $authUser->company_id;
            $validated['created_by'] = $authUser->id;

            if ($conflict = $this->assignmentConflict($authUser->company_id, $validated, null)) {
                return $conflict;
            }

            $template = DB::transaction(function () use ($validated, $authUser) {
                $template = WaiverTemplate::create($validated);
                $this->waivers->syncVersion($template, $authUser->id);
                return $template;
            });

            return response()->json([
                'success' => true,
                'message' => 'Waiver template created successfully',
                'data' => $template->fresh(['versions']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to create waiver template', $e);
        }
    }

    public function update(Request $request, WaiverTemplate $waiverTemplate): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardCanManageTemplates($authUser)) {
            return $guard;
        }
        if (!$this->authorizeRecordScope($waiverTemplate)) {
            return $this->forbidden();
        }

        try {
            $validated = $this->validatePayload($request, false);

            if ($conflict = $this->assignmentConflict($waiverTemplate->company_id, $validated, $waiverTemplate->id)) {
                return $conflict;
            }

            $template = DB::transaction(function () use ($waiverTemplate, $validated, $authUser) {
                $waiverTemplate->update($validated);
                // new version only if body/clauses actually changed
                $this->waivers->syncVersion($waiverTemplate, $authUser->id);
                return $waiverTemplate;
            });

            return response()->json([
                'success' => true,
                'message' => 'Waiver template updated successfully',
                'data' => $template->fresh(['versions']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to update waiver template', $e);
        }
    }

    public function updateStatus(Request $request, WaiverTemplate $waiverTemplate): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardCanManageTemplates($authUser)) {
            return $guard;
        }
        if (!$this->authorizeRecordScope($waiverTemplate)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'status' => 'required|in:draft,active,inactive,archived',
        ]);

        $waiverTemplate->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
            'data' => $waiverTemplate,
        ]);
    }

    public function versions(WaiverTemplate $waiverTemplate): JsonResponse
    {
        if (!$this->authorizeRecordScope($waiverTemplate)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => $waiverTemplate->versions()->with('creator:id,first_name,last_name')->get(),
        ]);
    }

    /** Tokens the builder can drop into the legal body for autofill. */
    public function contentTokens(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => WaiverService::contentTokens(),
        ]);
    }

    /**
     * Activities (packages/attractions/events) still available to assign — i.e. not
     * already tied to another template. The template being edited keeps its own items.
     */
    public function availableActivities(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        $type = $request->string('type')->toString(); // package|attraction|event
        $exceptTemplateId = $request->integer('except_template_id') ?: null;

        if (!array_key_exists($type, WaiverTemplate::ASSIGNMENT_COLUMNS)) {
            return response()->json(['success' => false, 'message' => 'Invalid activity type'], 422);
        }

        $column = WaiverTemplate::ASSIGNMENT_COLUMNS[$type];

        // IDs already claimed by other templates in this company
        $claimed = WaiverTemplate::where('company_id', $authUser->company_id)
            ->when($exceptTemplateId, fn ($q) => $q->where('id', '!=', $exceptTemplateId))
            ->pluck($column)
            ->flatMap(fn ($ids) => $ids ?? [])
            ->unique()
            ->values()
            ->all();

        $items = $this->activityList($type, $authUser, $claimed);

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $type,
                'claimed_ids' => $claimed,
                'available' => $items,
            ],
        ]);
    }

    // ---- helpers ----

    private function activityList(string $type, $authUser, array $excludeIds)
    {
        $model = match ($type) {
            'package' => Package::class,
            'attraction' => Attraction::class,
            'event' => Event::class,
            default => null,
        };
        if (!$model) {
            return collect();
        }

        $query = $model::query();
        if ($authUser->company_id) {
            $query->whereHas('location', fn ($q) => $q->where('company_id', $authUser->company_id));
        }
        if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $query->where('location_id', $authUser->location_id);
        }

        return $query
            ->with('location:id,name')
            ->whereNotIn('id', $excludeIds)
            ->get(['id', 'name', 'location_id'])
            ->map(fn ($item) => [
                'id'            => $item->id,
                'name'          => $item->name,
                'location_id'   => $item->location_id,
                'location_name' => $item->location?->name ?? null,
            ]);
    }

    private function assignmentConflict(int $companyId, array $payload, ?int $exceptTemplateId): ?JsonResponse
    {
        foreach (WaiverTemplate::ASSIGNMENT_COLUMNS as $type => $column) {
            $incoming = $payload[$column] ?? null;
            if (empty($incoming)) {
                continue;
            }

            $claimed = WaiverTemplate::where('company_id', $companyId)
                ->when($exceptTemplateId, fn ($q) => $q->where('id', '!=', $exceptTemplateId))
                ->pluck($column)
                ->flatMap(fn ($ids) => $ids ?? [])
                ->all();

            $overlap = array_values(array_intersect($incoming, $claimed));
            if (!empty($overlap)) {
                return response()->json([
                    'success' => false,
                    'message' => "Some {$type}s are already assigned to another waiver template.",
                    'errors' => [$column => $overlap],
                ], 422);
            }
        }

        return null;
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'title' => "{$req}|string|max:255",
            'internal_description' => 'nullable|string',
            'status' => 'sometimes|in:draft,active,inactive,archived',
            'is_default' => 'sometimes|boolean',
            'body_text' => "{$req}|string",
            'validity_duration_days' => 'nullable|integer|min:1',
            'max_minors' => 'sometimes|integer|min:0|max:50',
            'duplicate_rule' => 'sometimes|in:none,allow,manager_only',
            'reminder_eligible' => 'sometimes|boolean',
            'assigned_package_ids' => 'nullable|array',
            'assigned_package_ids.*' => 'integer',
            'assigned_attraction_ids' => 'nullable|array',
            'assigned_attraction_ids.*' => 'integer',
            'assigned_event_ids' => 'nullable|array',
            'assigned_event_ids.*' => 'integer',
            'assigned_party_types' => 'nullable|array',
            'assigned_party_types.*' => 'string',
            'minor_section_enabled' => 'sometimes|boolean',
            'dob_required' => 'sometimes|boolean',
            'relationship_required' => 'sometimes|boolean',
            'photo_video_release_enabled' => 'sometimes|boolean',
            'medical_ack_enabled' => 'sometimes|boolean',
            'property_damage_enabled' => 'sometimes|boolean',
            'group_leader_clause_enabled' => 'sometimes|boolean',
            'electronic_consent_enabled' => 'sometimes|boolean',
            'marketing_consent_enabled' => 'sometimes|boolean',
            'marketing_consent_text' => 'nullable|string',
            'marketing_helper_text' => 'nullable|string',
            'crm_sync_allowed' => 'sometimes|boolean',
            'crm_sync_birthday' => 'sometimes|boolean',
            'crm_sync_minor' => 'sometimes|boolean',
            'attorney_reviewed' => 'sometimes|boolean',
        ]);
    }

    private function guardCanManageTemplates($authUser): ?JsonResponse
    {
        if (!$authUser) {
            return $this->forbidden();
        }
        if ($authUser->role === 'company_admin' || $authUser->role === 'admin') {
            return null;
        }
        if ($authUser->role === 'location_manager'
            && WaiverSetting::forCompany($authUser->company_id)->manager_can_build_templates) {
            return null;
        }
        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to manage waiver templates.',
        ], 403);
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
    }

    private function error(string $message, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}
