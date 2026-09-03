<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Waiver;
use App\Models\WaiverMinor;
use App\Models\WaiverProfile;
use App\Models\WaiverProfileDependent;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Traits\GuardsWrites;

class WaiverProfileController extends Controller
{
    use GuardsWrites;
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guardProfileRole($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'needs_review' => ['nullable', 'boolean'],
        ]);

        $query = WaiverProfile::query()
            ->with('lastLocation:id,name')
            ->withCount(['activeDependents as dependents_count']);

        $this->applyAuthScope($query, $request);

        $term = trim((string) ($validated['search'] ?? ''));
        if ($term !== '') {
            $digits = (string) preg_replace('/[^0-9]/', '', $term);

            $rawDigits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_raw, ''), ' ', ''), '(', ''), ')', ''), '-', ''), '.', ''), '+', '')";

            $query->where(function ($q) use ($term, $digits, $rawDigits) {
                $q->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhereRaw(
                        "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?",
                        ["%{$term}%"]
                    )
                    ->orWhereRaw(
                        "CONCAT(COALESCE(last_name, ''), ' ', COALESCE(first_name, '')) LIKE ?",
                        ["%{$term}%"]
                    );

                if (strlen($digits) >= 4) {
                    $q->orWhere('phone_digits', 'like', "%{$digits}%")
                        ->orWhereRaw("{$rawDigits} LIKE ?", ["%{$digits}%"]);
                }
            });
        }

        if ($request->boolean('needs_review')) {
            $query->where('needs_staff_review', true);
        }

        $profiles = $query
            ->orderByDesc('needs_staff_review')
            ->orderByDesc('last_waiver_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => $profiles->getCollection()->map(fn (WaiverProfile $p) => $this->presentRow($p))->values(),
            'meta' => [
                'current_page' => $profiles->currentPage(),
                'last_page' => $profiles->lastPage(),
                'per_page' => $profiles->perPage(),
                'total' => $profiles->total(),
            ],
        ]);
    }

    public function show(Request $request, WaiverProfile $waiverProfile): JsonResponse
    {
        if ($denied = $this->guardProfileRole($request)) {
            return $denied;
        }
        if ($denied = $this->guardCompanyAccess($request, $waiverProfile->company_id)) {
            return $denied;
        }

        $waiverProfile->load(['lastLocation:id,name']);
        $waiverProfile->loadCount(['activeDependents as dependents_count']);

        $dependents = $waiverProfile->dependents()
            ->orderByDesc('is_active')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'profile' => array_merge($this->presentRow($waiverProfile), [
                    'phone_e164' => $waiverProfile->phone_e164,
                    'created_at' => $waiverProfile->created_at?->toIso8601String(),
                    'dependents' => $dependents->map(fn (WaiverProfileDependent $d) => $this->presentDependent($d))->values(),
                ]),
                'history' => $this->submissionHistory($waiverProfile),
                'shared_phone_profiles' => $this->sharedPhoneProfiles($waiverProfile),
            ],
        ]);
    }

    public function update(Request $request, WaiverProfile $waiverProfile): JsonResponse
    {
        return $this->guardWrite('waiver profile update', ['waiver_profile_id' => $waiverProfile->id], fn () => $this->updateProfile($request, $waiverProfile));
    }

    private function updateProfile(Request $request, WaiverProfile $waiverProfile): JsonResponse
    {
        if ($denied = $this->guardProfileRole($request)) {
            return $denied;
        }
        if ($denied = $this->guardCompanyAccess($request, $waiverProfile->company_id)) {
            return $denied;
        }

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:40'],
            'needs_staff_review' => ['nullable', 'boolean'],
        ]);

        $changes = [];
        foreach (['first_name', 'last_name'] as $field) {
            if ($request->has($field)) {
                $value = trim((string) ($validated[$field] ?? ''));
                if ($value === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'A first and last name are both required on a customer record.',
                    ], 422);
                }
                $changes[$field] = $value;
            }
        }
        foreach (['email', 'date_of_birth'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $validated[$field] ?: null;
            }
        }

        if ($request->has('phone')) {
            $phone = trim((string) ($validated['phone'] ?? ''));
            $digits = WaiverProfile::digitsFor($phone);
            if (!$digits) {
                return response()->json([
                    'success' => false,
                    'message' => 'That phone number is not usable as a lookup key. Enter at least 7 digits.',
                ], 422);
            }

            $clash = WaiverProfile::where('company_id', $waiverProfile->company_id)
                ->where('phone_digits', $digits)
                ->where('id', '!=', $waiverProfile->id)
                ->first();
            if ($clash) {
                return response()->json([
                    'success' => false,
                    'message' => sprintf(
                        'That number already belongs to %s. Two records cannot share a lookup number.',
                        $clash->full_name ?: ('record #' . $clash->id)
                    ),
                ], 422);
            }

            $changes['phone_digits'] = $digits;
            $changes['phone_e164'] = SmsService::toE164($phone);
            $changes['phone_raw'] = $phone;
        }

        if ($request->has('needs_staff_review')) {
            $changes['needs_staff_review'] = (bool) $validated['needs_staff_review'];
        }

        if ($changes) {
            $waiverProfile->update($changes);
        }

        ActivityLog::log(
            'waiver_profile_updated',
            'waivers',
            sprintf('Updated the returning-customer record for %s', $waiverProfile->full_name ?: ('#' . $waiverProfile->id)),
            $this->resolveAuthUser($request)?->id,
            $waiverProfile->last_location_id,
            'waiver_profile',
            $waiverProfile->id,
            $changes
        );

        return $this->show($request, $waiverProfile->fresh() ?? $waiverProfile);
    }

    public function storeDependent(Request $request, WaiverProfile $waiverProfile): JsonResponse
    {
        return $this->guardWrite('waiver profile dependent create', ['waiver_profile_id' => $waiverProfile->id], fn () => $this->createDependent($request, $waiverProfile));
    }

    private function createDependent(Request $request, WaiverProfile $waiverProfile): JsonResponse
    {
        if ($denied = $this->guardProfileRole($request)) {
            return $denied;
        }
        if ($denied = $this->guardCompanyAccess($request, $waiverProfile->company_id)) {
            return $denied;
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'relationship' => ['nullable', 'string', 'max:60'],
        ]);

        $first = trim($validated['first_name']);
        $last = trim($validated['last_name']);
        $dob = $validated['date_of_birth'] ?? null;

        $waiverProfile->loadMissing('dependents');
        $existing = $waiverProfile->dependents
            ->first(fn (WaiverProfileDependent $d) => $d->matches($first, $last, $dob ? substr((string) $dob, 0, 10) : null));

        if ($existing) {
            $existing->update([
                'is_active' => true,
                'relationship' => $validated['relationship'] ?? $existing->relationship,
            ]);
            $dependent = $existing;
        } else {
            $dependent = $waiverProfile->dependents()->create([
                'first_name' => $first,
                'last_name' => $last,
                'date_of_birth' => $dob,
                'relationship' => $validated['relationship'] ?? null,
                'is_active' => true,
            ]);
        }

        ActivityLog::log(
            'waiver_profile_dependent_created',
            'waivers',
            sprintf('Added the dependent "%s" to %s', $dependent->full_name, $waiverProfile->full_name ?: ('#' . $waiverProfile->id)),
            $this->resolveAuthUser($request)?->id,
            $waiverProfile->last_location_id,
            'waiver_profile_dependent',
            $dependent->id
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentDependent($dependent),
        ], 201);
    }

    public function updateDependent(Request $request, WaiverProfileDependent $dependent): JsonResponse
    {
        return $this->guardWrite('waiver profile dependent update', ['dependent_id' => $dependent->id], fn () => $this->changeDependent($request, $dependent));
    }

    private function changeDependent(Request $request, WaiverProfileDependent $dependent): JsonResponse
    {
        if ($denied = $this->guardProfileRole($request)) {
            return $denied;
        }

        $profile = $dependent->profile;
        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'That dependent is not attached to a customer record.'], 404);
        }
        if ($denied = $this->guardCompanyAccess($request, $profile->company_id)) {
            return $denied;
        }

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $changes = [];
        foreach (['first_name', 'last_name'] as $field) {
            if ($request->has($field)) {
                $value = trim((string) ($validated[$field] ?? ''));
                if ($value === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'A dependent needs both a first and a last name.',
                    ], 422);
                }
                $changes[$field] = $value;
            }
        }
        foreach (['date_of_birth', 'relationship'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $validated[$field] ?: null;
            }
        }
        if ($request->has('is_active')) {
            $changes['is_active'] = (bool) $validated['is_active'];
        }

        if ($changes) {
            $dependent->update($changes);
        }

        ActivityLog::log(
            'waiver_profile_dependent_updated',
            'waivers',
            sprintf('Updated the dependent "%s" on %s', $dependent->full_name, $profile->full_name ?: ('#' . $profile->id)),
            $this->resolveAuthUser($request)?->id,
            $profile->last_location_id,
            'waiver_profile_dependent',
            $dependent->id,
            $changes
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentDependent($dependent->fresh() ?? $dependent),
        ]);
    }

    public function destroyDependent(Request $request, WaiverProfileDependent $dependent): JsonResponse
    {
        return $this->guardWrite('waiver profile dependent retire', ['dependent_id' => $dependent->id], fn () => $this->retireDependent($request, $dependent));
    }

    private function retireDependent(Request $request, WaiverProfileDependent $dependent): JsonResponse
    {
        if ($denied = $this->guardProfileRole($request)) {
            return $denied;
        }

        $profile = $dependent->profile;
        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'That dependent is not attached to a customer record.'], 404);
        }
        if ($denied = $this->guardCompanyAccess($request, $profile->company_id)) {
            return $denied;
        }

        $dependent->update(['is_active' => false]);

        ActivityLog::log(
            'waiver_profile_dependent_retired',
            'waivers',
            sprintf('Retired the dependent "%s" on %s', $dependent->full_name, $profile->full_name ?: ('#' . $profile->id)),
            $this->resolveAuthUser($request)?->id,
            $profile->last_location_id,
            'waiver_profile_dependent',
            $dependent->id
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentDependent($dependent->fresh() ?? $dependent),
            'message' => 'Dependent retired. Past waivers still show them.',
        ]);
    }

    protected function guardProfileRole(Request $request): ?JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);

        if (!$authUser || !in_array((string) $authUser->role, ['company_admin', 'admin', 'location_manager'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a location manager or an administrator can view or change saved customer records.',
            ], 403);
        }

        return null;
    }

    protected function submissionHistory(WaiverProfile $profile): array
    {
        $waivers = Waiver::where('waiver_profile_id', $profile->id)
            ->with(['template:id,title', 'version:id,version', 'location:id,name'])
            ->select([
                'id',
                'waiver_template_id',
                'waiver_template_version_id',
                'location_id',
                'status',
                'typed_legal_name',
                'agreement_accepted',
                'electronic_consent_accepted',
                'photo_video_consent',
                'marketing_consent_status',
                'source',
                'submitted_at',
                'checked_in_at',
                'created_at',
            ])
            ->selectRaw("(signature_image IS NOT NULL AND signature_image <> '') AS has_signature")
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        if ($waivers->isEmpty()) {
            return [];
        }

        $minors = WaiverMinor::whereIn('waiver_id', $waivers->pluck('id'))
            ->orderBy('first_name')
            ->orderBy('id')
            ->get()
            ->groupBy('waiver_id');

        return $waivers->map(function (Waiver $waiver) use ($minors) {
            $rows = $minors->get($waiver->id) ?? collect();

            return [
                'id' => $waiver->id,
                'submitted_at' => $waiver->submitted_at?->toIso8601String(),
                'created_at' => $waiver->created_at?->toIso8601String(),
                'status' => $waiver->status,
                'source' => $waiver->source,
                'template_title' => $waiver->template?->title,
                'version' => $waiver->version?->version,
                'location_name' => $waiver->location?->name,
                'typed_legal_name' => $waiver->typed_legal_name,
                'has_signature' => (bool) $waiver->has_signature,
                'agreement_accepted' => (bool) $waiver->agreement_accepted,
                'electronic_consent_accepted' => (bool) $waiver->electronic_consent_accepted,
                'photo_video_consent' => (bool) $waiver->photo_video_consent,
                'marketing_consent_status' => $waiver->marketing_consent_status,
                'checked_in_at' => $waiver->checked_in_at?->toIso8601String(),
                'dependents' => $rows->map(fn (WaiverMinor $m) => [
                    'id' => $m->id,
                    'waiver_profile_dependent_id' => $m->waiver_profile_dependent_id,
                    'name' => $m->full_name,
                    'date_of_birth' => $m->date_of_birth?->toDateString(),
                    'relationship' => $m->relationship,
                    'was_new_this_visit' => (bool) $m->was_new_this_visit,
                ])->values()->all(),
                'new_dependents_count' => $rows->where('was_new_this_visit', true)->count(),
            ];
        })->values()->all();
    }

    protected function sharedPhoneProfiles(WaiverProfile $profile): array
    {
        if (!$profile->phone_digits) {
            return [];
        }

        return WaiverProfile::where('company_id', $profile->company_id)
            ->where('phone_digits', $profile->phone_digits)
            ->where('id', '!=', $profile->id)
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'email', 'submissions_count', 'last_waiver_at'])
            ->map(fn (WaiverProfile $p) => [
                'id' => $p->id,
                'name' => $p->full_name,
                'email' => $p->email,
                'submissions_count' => (int) $p->submissions_count,
                'last_waiver_at' => $p->last_waiver_at?->toIso8601String(),
            ])->values()->all();
    }

    protected function presentRow(WaiverProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'full_name' => $profile->full_name,
            'email' => $profile->email,
            'phone' => $profile->phone_raw ?: $profile->phone_e164,
            'phone_digits' => $profile->phone_digits,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
            'needs_staff_review' => (bool) $profile->needs_staff_review,
            'submissions_count' => (int) $profile->submissions_count,
            'last_waiver_at' => $profile->last_waiver_at?->toIso8601String(),
            'dependents_count' => (int) ($profile->dependents_count ?? 0),
            'last_location_id' => $profile->last_location_id,
            'last_location_name' => $profile->relationLoaded('lastLocation') ? $profile->lastLocation?->name : null,
        ];
    }

    protected function presentDependent(WaiverProfileDependent $dependent): array
    {
        return [
            'id' => $dependent->id,
            'waiver_profile_id' => $dependent->waiver_profile_id,
            'first_name' => $dependent->first_name,
            'last_name' => $dependent->last_name,
            'full_name' => $dependent->full_name,
            'date_of_birth' => $dependent->date_of_birth?->toDateString(),
            'relationship' => $dependent->relationship,
            'is_active' => (bool) $dependent->is_active,
        ];
    }
}
