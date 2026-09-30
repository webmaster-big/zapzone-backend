<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Location;
use App\Models\User;
use App\Models\VisitFollowUp;
use App\Services\VisitFollowUpService;
use App\Support\CompletedVisit;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitFollowUpController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(protected VisitFollowUpService $service)
    {
    }

    public function visit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_type' => ['required', Rule::in(VisitFollowUp::VISIT_TYPES)],
            'visit_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($denied = $this->denyVisit($validated['visit_type'], (int) $validated['visit_id'])) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->summaryFor($validated['visit_type'], (int) $validated['visit_id']),
        ]);
    }

    public function sendThanks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_type' => ['required', Rule::in([VisitFollowUp::VISIT_BOOKING, VisitFollowUp::VISIT_EVENT_PURCHASE])],
            'visit_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($denied = $this->denyVisit($validated['visit_type'], (int) $validated['visit_id'])) {
            return $denied;
        }

        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        $visit = CompletedVisit::find($validated['visit_type'], (int) $validated['visit_id']);

        if (!$visit) {
            return response()->json(['success' => false, 'message' => 'That visit could not be found.'], 404);
        }

        try {
            $summary = $this->service->sendThanksFor($visit, $this->staff($request));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $row = collect($summary['thanks'])->first(fn (array $thanks) => $thanks['is_current_recipient'] ?? false);

        if (($row['status'] ?? null) !== VisitFollowUp::STATUS_SENT) {
            return response()->json([
                'success' => false,
                'message' => ($row['status'] ?? null) === VisitFollowUp::STATUS_FAILED
                    ? 'The email could not be sent yet: ' . ($row['error'] ?: 'unknown error') . ' It will be tried again automatically.'
                    : ($row['error'] ?? 'The email was not sent.'),
                'data' => $summary,
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Email sent.', 'data' => $summary]);
    }

    public function sendNow(Request $request, VisitFollowUp $visitFollowUp): JsonResponse
    {
        if ($denied = $this->denyRow($visitFollowUp)) {
            return $denied;
        }

        try {
            $row = $this->service->sendNow($visitFollowUp, $this->staff($request));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => $row->status === VisitFollowUp::STATUS_SENT,
            'message' => match ($row->status) {
                VisitFollowUp::STATUS_SENT => 'Email sent.',
                VisitFollowUp::STATUS_SKIPPED, VisitFollowUp::STATUS_CANCELED => $row->error ?: 'This email was not sent.',
                default => 'The email could not be sent: ' . ($row->error ?: 'unknown error'),
            },
            'data' => $this->service->summaryFor($row->visit_type, (int) $row->visit_id),
        ], $row->status === VisitFollowUp::STATUS_SENT ? 200 : 422);
    }

    public function cancel(Request $request, VisitFollowUp $visitFollowUp): JsonResponse
    {
        if ($denied = $this->denyRow($visitFollowUp)) {
            return $denied;
        }

        try {
            $row = $this->service->cancel($visitFollowUp, $this->staff($request));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'The email will not be sent.',
            'data' => $this->service->summaryFor($row->visit_type, (int) $row->visit_id),
        ]);
    }

    public function ratings(Request $request): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'max_rating' => ['nullable', 'integer', 'between:1,5'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $user = $this->staff($request);
        $locationIds = $this->visibleLocationIds($user, $validated['location_id'] ?? null);

        $base = VisitFollowUp::where('company_id', $user->company_id)
            ->where('kind', VisitFollowUp::KIND_REVIEW)
            ->whereNotNull('rated_at')
            ->whereIn('location_id', $locationIds);

        DateRange::apply($base, 'rated_at', $validated['start_date'] ?? null, $validated['end_date'] ?? null);

        $distribution = (clone $base)->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $requested = VisitFollowUp::where('company_id', $user->company_id)
            ->where('kind', VisitFollowUp::KIND_REVIEW)
            ->where('status', VisitFollowUp::STATUS_SENT)
            ->whereIn('location_id', $locationIds);
        DateRange::apply($requested, 'sent_at', $validated['start_date'] ?? null, $validated['end_date'] ?? null);

        $page = (clone $base)
            ->with('location:id,name')
            ->when(isset($validated['max_rating']), fn ($query) => $query->where('rating', '<=', $validated['max_rating']))
            ->orderByDesc('rated_at')
            ->paginate($validated['per_page'] ?? 20);

        $count = (int) $distribution->sum();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'requested' => $requested->count(),
                    'rated' => $count,
                    'average' => $count > 0
                        ? round(collect($distribution)->reduce(fn ($carry, $total, $rating) => $carry + ((int) $rating * (int) $total), 0) / $count, 2)
                        : null,
                    'distribution' => collect([1, 2, 3, 4, 5])->mapWithKeys(fn ($stars) => [$stars => (int) ($distribution[$stars] ?? 0)])->all(),
                ],
                'ratings' => collect($page->items())->map(fn (VisitFollowUp $row) => $row->toStaffArray() + [
                    'location_name' => $row->location?->name,
                    'visit_path' => $this->service->adminPath($row->visit_type, (int) $row->visit_id),
                ])->all(),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        ]);
    }

    protected function visibleLocationIds(User $user, ?int $requested): array
    {
        $companyLocations = Location::where('company_id', $user->company_id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            return [(int) $user->location_id];
        }

        if ($requested !== null) {
            return in_array($requested, $companyLocations, true) ? [$requested] : [];
        }

        return $companyLocations;
    }

    protected function denyVisit(string $visitType, int $visitId): ?JsonResponse
    {
        $subject = CompletedVisit::find($visitType, $visitId)?->subject;

        if (!$subject) {
            return response()->json(['success' => false, 'message' => 'That visit could not be found.'], 404);
        }

        return $this->denyForeignRecord($subject, 'visit');
    }

    protected function denyRow(VisitFollowUp $row): ?JsonResponse
    {
        $subject = CompletedVisit::find($row->visit_type, (int) $row->visit_id)?->subject;
        $allowed = (int) $row->company_id === (int) $this->resolveAuthUser()?->company_id
            && ($subject ? $this->denyForeignRecord($subject, 'visit') === null : $this->authorizeRecordScope($row));

        if (!$allowed) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this email.'], 403);
        }

        return null;
    }

    protected function unavailable(): ?JsonResponse
    {
        return $this->service->isAvailable()
            ? null
            : response()->json(['success' => false, 'message' => 'Follow-up emails are not set up on this site yet.'], 404);
    }

    protected function staff(Request $request): User
    {
        $user = $this->resolveAuthUser($request);

        abort_unless($user instanceof User, 403, 'Staff sign-in is required.');

        return $user;
    }
}
