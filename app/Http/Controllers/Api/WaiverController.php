<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Waiver;
use App\Models\WaiverDeletionLog;
use App\Models\WaiverSetting;
use App\Models\WaiverTemplate;
use App\Services\WaiverService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WaiverController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(private WaiverService $waivers)
    {
    }

    /**
     * Search / list completed waivers. Defaults to the selected date (today) unless
     * `all=1` is passed. Most recently completed first.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Waiver::with([
                'template:id,title',
                'location:id,name',
                'minors:id,waiver_id,first_name,last_name,date_of_birth',
                'booking:id,reference_number',
                'event:id,name',
            ]);
            $this->applyAuthScope($query, $request);

            // status: default to completed (the daily lookup), allow override
            $status = $request->string('status')->toString();
            if ($status !== '') {
                $query->where('status', $status);
            } else {
                $query->where('status', Waiver::STATUS_COMPLETED);
            }

            // date scope: default selected date (today) unless searching all
            if (!$request->boolean('all')) {
                $date = $request->date('date') ?? now();
                $query->whereDate('selected_date', $date);
            }

            $this->applySearchFilters($query, $request);

            $query->orderByDesc('submitted_at')->orderByDesc('id');

            $waivers = $query->paginate($request->integer('per_page', 25));

            return response()->json([
                'success' => true,
                'data' => [
                    'waivers' => $waivers->items(),
                    'pagination' => $this->paginationMeta($waivers),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to fetch waivers', $e);
        }
    }

    public function show(Waiver $waiver): JsonResponse
    {
        if (!$this->authorizeRecordScope($waiver)) {
            return $this->forbidden();
        }

        $waiver->load([
            'template:id,title,duplicate_rule',
            'version:id,version',
            'location:id,name',
            'customer:id,first_name,last_name,email,phone',
            'minors',
            'booking:id,reference_number,booking_date',
            'event:id,name',
            'creator:id,first_name,last_name',
            'assigner:id,first_name,last_name',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'waiver' => $waiver,
                // legal body with autofill values applied (read-only view)
                'rendered_body' => $this->waivers->renderForWaiver($waiver),
            ],
        ]);
    }

    /**
     * Manager/admin: assign a waiver to a customer + date + template (outside the
     * normal booking flow) and optionally send the link. Supports manager-assigned
     * duplicates for the same customer/date.
     */
    public function assign(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardManager($authUser)) {
            return $guard;
        }

        $validated = $request->validate([
            'waiver_template_id' => 'required|exists:waiver_templates,id',
            'selected_date' => 'required|date',
            'customer_id' => 'nullable|exists:customers,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'event_id' => 'nullable|exists:events,id',
            'attraction_purchase_id' => 'nullable|exists:attraction_purchases,id',
            'location_id' => 'nullable|exists:locations,id',
            'adult_email' => 'nullable|email',
            'adult_phone' => 'nullable|string|max:30',
            // activity label for {{activity_name}} when there's no concrete linked record
            'activity_name' => 'nullable|string|max:255',
        ]);

        $template = WaiverTemplate::findOrFail($validated['waiver_template_id']);
        if (!$this->authorizeRecordScope($template)) {
            return $this->forbidden();
        }

        $version = $this->waivers->syncVersion($template, $authUser->id);

        $waiver = Waiver::create([
            'company_id' => $template->company_id,
            'location_id' => $validated['location_id'] ?? $template->location_id,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'customer_id' => $validated['customer_id'] ?? null,
            'booking_id' => $validated['booking_id'] ?? null,
            'event_id' => $validated['event_id'] ?? null,
            'attraction_purchase_id' => $validated['attraction_purchase_id'] ?? null,
            'manual_activity_name' => $validated['activity_name'] ?? null,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $validated['selected_date'],
            'adult_email' => $validated['adult_email'] ?? null,
            'adult_phone' => $validated['adult_phone'] ?? null,
            'source' => Waiver::SOURCE_STAFF_SENT,
            'created_by' => $authUser->id,
            'assigned_by' => $authUser->id,
            'is_manager_assigned' => true,
        ]);

        try {
            app(\App\Services\EmailNotificationService::class)
                ->triggerWaiverNotification($waiver, \App\Models\EmailNotification::TRIGGER_WAIVER_STAFF_SENT);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send assigned-waiver notification', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Waiver assigned',
            'data' => $waiver,
        ], 201);
    }

    public function kioskSession(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardManager($authUser)) {
            return $guard;
        }

        $validated = $request->validate([
            'source_type' => 'required|in:booking,attraction_purchase,event_purchase,package,attraction,event',
            'source_id'   => 'required|integer',
            'template_id' => 'nullable|integer',
            'selected_date' => 'nullable|date',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        $templateOverride = null;
        if (!empty($validated['template_id'])) {
            $templateOverride = WaiverTemplate::where('id', $validated['template_id'])
                ->where('company_id', $authUser->company_id)
                ->whereNotIn('status', ['archived'])
                ->first();
        }

        $waiver = match ($validated['source_type']) {
            'booking'             => $this->kioskForBooking($authUser, $validated, $templateOverride),
            'attraction_purchase' => $this->kioskForAttractionPurchase($authUser, $validated, $templateOverride),
            'event_purchase'      => $this->kioskForEventPurchase($authUser, $validated, $templateOverride),
            default               => $this->resolveActivityKioskWaiver($authUser, $validated, $templateOverride),
        };

        if (!$waiver) {
            return response()->json([
                'success' => false,
                'message' => 'No waiver template is assigned to this activity. Activate a template or assign one to this activity first.',
            ], 422);
        }
        if (!$this->authorizeRecordScope($waiver)) {
            return $this->forbidden();
        }

        $base = rtrim(config('app.frontend_url', config('app.url', '')), '/');

        return response()->json([
            'success' => true,
            'data' => [
                'access_token'     => $waiver->access_token,
                'kiosk_url'        => $base . '/waiver/kiosk-session/' . $waiver->access_token,
                'status'           => $waiver->status,
                'already_completed' => $waiver->status === Waiver::STATUS_COMPLETED,
            ],
        ]);
    }

    private function kioskForBooking($authUser, array $data, ?WaiverTemplate $template): ?Waiver
    {
        $booking = \App\Models\Booking::with(['location', 'customer', 'attractions'])->findOrFail($data['source_id']);

        $existing = Waiver::where('booking_id', $booking->id)->first();
        if ($existing) {
            return $existing;
        }

        $resolved = $template ?? WaiverTemplate::resolveForActivity(
            $booking->location?->company_id ?? $authUser->company_id,
            $booking->location_id,
            $booking->package_id,
            $booking->attractions?->pluck('id')->all() ?? [],
        );
        if (!$resolved) {
            return null;
        }

        $companyId = $booking->location?->company_id ?? $authUser->company_id;
        $version = $this->waivers->syncVersion($resolved, $authUser->id);

        return Waiver::create([
            'company_id'                => $companyId,
            'location_id'               => $booking->location_id,
            'waiver_template_id'        => $resolved->id,
            'waiver_template_version_id' => $version->id,
            'status'                    => Waiver::STATUS_PENDING,
            'customer_id'               => $booking->customer_id,
            'booking_id'                => $booking->id,
            'selected_date'             => $booking->booking_date,
            'adult_email'               => $booking->customer?->email ?? $booking->guest_email,
            'adult_phone'               => $booking->customer?->phone ?? $booking->guest_phone,
            'source'                    => Waiver::SOURCE_KIOSK,
            'created_by'                => $authUser->id,
            'assigned_by'               => $authUser->id,
            'is_manager_assigned'       => true,
        ]);
    }

    private function kioskForAttractionPurchase($authUser, array $data, ?WaiverTemplate $template): ?Waiver
    {
        $purchase = \App\Models\AttractionPurchase::with(['location', 'customer', 'attraction'])->findOrFail($data['source_id']);

        $existing = $this->waivers->ensureForAttractionPurchase($purchase);
        if ($existing) {
            return $existing;
        }

        if (!$template) {
            return null;
        }

        $companyId = $purchase->location?->company_id ?? $authUser->company_id;
        $version = $this->waivers->syncVersion($template, $authUser->id);

        return Waiver::create([
            'company_id'                => $companyId,
            'location_id'               => $purchase->location_id,
            'waiver_template_id'        => $template->id,
            'waiver_template_version_id' => $version->id,
            'status'                    => Waiver::STATUS_PENDING,
            'customer_id'               => $purchase->customer_id,
            'attraction_purchase_id'    => $purchase->id,
            'selected_date'             => $purchase->purchase_date ?? now()->toDateString(),
            'adult_email'               => $purchase->customer?->email ?? $purchase->guest_email,
            'adult_phone'               => $purchase->customer?->phone ?? $purchase->guest_phone,
            'source'                    => Waiver::SOURCE_KIOSK,
            'created_by'                => $authUser->id,
            'assigned_by'               => $authUser->id,
            'is_manager_assigned'       => true,
        ]);
    }

    private function kioskForEventPurchase($authUser, array $data, ?WaiverTemplate $template): ?Waiver
    {
        $purchase = \App\Models\EventPurchase::with(['location', 'customer', 'event'])->findOrFail($data['source_id']);

        $existing = $this->waivers->ensureForEventPurchase($purchase);
        if ($existing) {
            return $existing;
        }

        if (!$template) {
            return null;
        }

        $companyId = $purchase->location?->company_id ?? $authUser->company_id;
        $version = $this->waivers->syncVersion($template, $authUser->id);

        return Waiver::create([
            'company_id'                => $companyId,
            'location_id'               => $purchase->location_id,
            'waiver_template_id'        => $template->id,
            'waiver_template_version_id' => $version->id,
            'status'                    => Waiver::STATUS_PENDING,
            'customer_id'               => $purchase->customer_id,
            'event_id'                  => $purchase->event_id,
            'selected_date'             => $purchase->purchase_date ?? now()->toDateString(),
            'adult_email'               => $purchase->customer?->email ?? $purchase->guest_email,
            'adult_phone'               => $purchase->customer?->phone ?? $purchase->guest_phone,
            'source'                    => Waiver::SOURCE_KIOSK,
            'created_by'                => $authUser->id,
            'assigned_by'               => $authUser->id,
            'is_manager_assigned'       => true,
        ]);
    }

    private function resolveActivityKioskWaiver($authUser, array $data, ?WaiverTemplate $templateOverride = null): ?Waiver
    {
        $companyId = $authUser->company_id;
        $locationId = $data['location_id'] ?? $authUser->location_id;
        $id = (int) $data['source_id'];

        $packageId = null;
        $attractionIds = [];
        $eventId = null;
        $activityName = null;

        if ($data['source_type'] === 'package') {
            $model = \App\Models\Package::find($id);
            if (!$model) {
                return null;
            }
            $packageId = $model->id;
            $activityName = $model->name;
            $locationId = $locationId ?? $model->location_id;
        } elseif ($data['source_type'] === 'attraction') {
            $model = \App\Models\Attraction::find($id);
            if (!$model) {
                return null;
            }
            $attractionIds = [$model->id];
            $activityName = $model->name;
            $locationId = $locationId ?? $model->location_id;
        } else {
            $model = \App\Models\Event::find($id);
            if (!$model) {
                return null;
            }
            $eventId = $model->id;
            $activityName = $model->name;
            $locationId = $locationId ?? $model->location_id;
        }

        $template = $templateOverride
            ?? WaiverTemplate::resolveForActivity($companyId, $locationId, $packageId, $attractionIds, $eventId);
        if (!$template) {
            return null;
        }

        $version = $this->waivers->syncVersion($template, $authUser->id);

        return Waiver::create([
            'company_id' => $companyId,
            'location_id' => $locationId ?? $template->location_id,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $data['selected_date'] ?? now()->toDateString(),
            'manual_activity_name' => $activityName,
            'source' => Waiver::SOURCE_KIOSK,
            'created_by' => $authUser->id,
            'assigned_by' => $authUser->id,
            'is_manager_assigned' => true,
        ]);
    }

    /**
     * Admin-only delete. Writes a deletion-log row (which survives the soft delete)
     * and an activity-log entry before soft-deleting.
     */
    public function destroy(Request $request, Waiver $waiver): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardAdmin($authUser)) {
            return $guard;
        }
        if (!$this->authorizeRecordScope($waiver)) {
            return $this->forbidden();
        }

        $reason = $request->input('reason');

        try {
            DB::transaction(function () use ($waiver, $authUser, $reason) {
                WaiverDeletionLog::create([
                    'company_id' => $waiver->company_id,
                    'waiver_id' => $waiver->id,
                    'deleted_by' => $authUser->id,
                    'reason' => $reason,
                    'snapshot' => [
                        'adult_name' => $waiver->adult_full_name,
                        'adult_email' => $waiver->adult_email,
                        'adult_phone' => $waiver->adult_phone,
                        'selected_date' => optional($waiver->selected_date)->toDateString(),
                        'status' => $waiver->status,
                        'waiver_template_id' => $waiver->waiver_template_id,
                        'submitted_at' => optional($waiver->submitted_at)->toIso8601String(),
                    ],
                ]);

                $waiver->update(['status' => Waiver::STATUS_DELETED, 'deleted_by' => $authUser->id]);
                $waiver->delete();

                ActivityLog::log(
                    action: 'Waiver Deleted',
                    category: 'delete',
                    description: "Waiver #{$waiver->id} deleted" . ($reason ? ": {$reason}" : ''),
                    userId: $authUser->id,
                    locationId: $waiver->location_id,
                    entityType: 'waiver',
                    entityId: $waiver->id,
                    metadata: ['deleted_at' => now()->toIso8601String()],
                );
            });

            return response()->json(['success' => true, 'message' => 'Waiver deleted']);
        } catch (\Throwable $e) {
            return $this->error('Failed to delete waiver', $e);
        }
    }

    /** Manager/admin: render a waiver as a printable PDF. */
    public function print(Request $request, Waiver $waiver)
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardPrintExport($authUser)) {
            return $guard;
        }
        if (!$this->authorizeRecordScope($waiver)) {
            return $this->forbidden();
        }

        $waiver->load(['template:id,title', 'location:id,name', 'minors', 'company:id,name']);

        $pdf = Pdf::loadView('waivers.print', [
            'waiver' => $waiver,
            'renderedBody' => $this->waivers->renderForWaiver($waiver),
        ]);

        return $pdf->download("waiver-{$waiver->id}.pdf");
    }

    /** Manager/admin: export the filtered waiver set as rows (frontend builds the file). */
    public function export(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardPrintExport($authUser)) {
            return $guard;
        }

        $query = Waiver::with(['template:id,title', 'location:id,name', 'minors:id,waiver_id,first_name,last_name,date_of_birth'])
            ->where('status', $request->string('status')->toString() ?: Waiver::STATUS_COMPLETED);
        $this->applyAuthScope($query, $request);

        if (!$request->boolean('all')) {
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('selected_date', [$request->date('start_date'), $request->date('end_date')]);
            } else {
                $query->whereDate('selected_date', $request->date('date') ?? now());
            }
        }
        $this->applySearchFilters($query, $request);

        $rows = $query->orderByDesc('submitted_at')->limit(5000)->get()->map(fn (Waiver $w) => [
            'id' => $w->id,
            'adult_name' => $w->adult_full_name,
            'email' => $w->adult_email,
            'phone' => $w->adult_phone,
            'selected_date' => (string) $w->selected_date,
            'status' => $w->status,
            'marketing_consent' => $w->marketing_consent_status,
            'source' => $w->source,
            'template' => $w->template?->title,
            'location' => $w->location?->name,
            'submitted_at' => $w->submitted_at?->toIso8601String(),
            'minors' => $w->minors->map(fn ($m) => trim($m->first_name . ' ' . $m->last_name))->implode('; '),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['waivers' => $rows, 'count' => $rows->count()],
        ]);
    }

    /** Admin (or manager if enabled): the deletion log. */
    public function deletionLog(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        $setting = WaiverSetting::forCompany($authUser->company_id);
        $isAdmin = in_array($authUser->role, ['company_admin', 'admin'], true);
        if (!$isAdmin && !($authUser->role === 'location_manager' && $setting->manager_can_view_deletion_log)) {
            return $this->forbidden();
        }

        $logs = WaiverDeletionLog::with('deleter:id,first_name,last_name')
            ->where('company_id', $authUser->company_id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs->items(),
                'pagination' => $this->paginationMeta($logs),
            ],
        ]);
    }

    /**
     * Read-only: the waiver(s) connected to a booking / attraction purchase / event
     * purchase / customer. All staff may view (transparency on check-in & detail pages).
     * Returns a compact summary, never the full legal record.
     */
    public function entityWaivers(Request $request): JsonResponse
    {
        try {
            $type = $request->string('type')->toString();
            $id = $request->integer('id');
            if (!$id) {
                return response()->json(['success' => false, 'message' => 'Missing id'], 422);
            }

            $query = Waiver::with([
                'template:id,title',
                'location:id,name',
                'minors:id,waiver_id,first_name,last_name,date_of_birth',
            ]);
            $this->applyAuthScope($query, $request);

            switch ($type) {
                case 'booking':
                    $query->where('booking_id', $id);
                    break;
                case 'attraction_purchase':
                    $query->where('attraction_purchase_id', $id);
                    break;
                case 'event_purchase':
                    // waivers link to events by event_id + customer + date, not by purchase id
                    $purchase = \App\Models\EventPurchase::find($id);
                    if (!$purchase) {
                        return response()->json(['success' => true, 'data' => ['waivers' => []]]);
                    }
                    $query->where('event_id', $purchase->event_id)
                        ->whereDate('selected_date', $purchase->purchase_date)
                        ->when($purchase->customer_id, fn ($q) => $q->where('customer_id', $purchase->customer_id));
                    break;
                case 'customer':
                    $query->where('customer_id', $id);
                    break;
                default:
                    return response()->json(['success' => false, 'message' => 'Invalid type'], 422);
            }

            $waivers = $query->orderByDesc('submitted_at')->orderByDesc('id')->limit(200)->get()
                ->map(fn (Waiver $w) => [
                    'id' => $w->id,
                    'status' => $w->status,
                    'adult_name' => $w->adult_full_name,
                    'adult_email' => $w->adult_email,
                    'adult_phone' => $w->adult_phone,
                    'selected_date' => (string) $w->selected_date,
                    'template' => $w->template?->title,
                    'location' => $w->location?->name,
                    'source' => $w->source,
                    'marketing_consent_status' => $w->marketing_consent_status,
                    'submitted_at' => $w->submitted_at?->toIso8601String(),
                    'minors' => $w->minors->map(fn ($m) => trim($m->first_name . ' ' . $m->last_name))->values(),
                    // pending links can be re-sent/opened by staff; completed have none
                    'signing_url' => $w->status === Waiver::STATUS_PENDING ? $w->signing_url : null,
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'waivers' => $waivers,
                    'summary' => [
                        'total' => $waivers->count(),
                        'completed' => $waivers->where('status', Waiver::STATUS_COMPLETED)->count(),
                        'pending' => $waivers->where('status', Waiver::STATUS_PENDING)->count(),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to fetch connected waivers', $e);
        }
    }

    // ---- helpers ----

    private function applySearchFilters($query, Request $request): void
    {
        if ($request->filled('adult_name')) {
            $name = $request->string('adult_name');
            $query->where(fn ($q) => $q->where('adult_first_name', 'like', "%{$name}%")
                ->orWhere('adult_last_name', 'like', "%{$name}%"));
        }
        if ($request->filled('minor_name')) {
            $name = $request->string('minor_name');
            $query->whereHas('minors', fn ($q) => $q->where('first_name', 'like', "%{$name}%")
                ->orWhere('last_name', 'like', "%{$name}%"));
        }
        if ($request->filled('email')) {
            $query->where('adult_email', 'like', '%' . $request->string('email') . '%');
        }
        if ($request->filled('phone')) {
            $query->where('adult_phone', 'like', '%' . $request->string('phone') . '%');
        }
        if ($request->filled('phone_last4')) {
            $query->where('adult_phone', 'like', '%' . $request->string('phone_last4'));
        }
        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->integer('booking_id'));
        }
        if ($request->filled('event_id')) {
            $query->where('event_id', $request->integer('event_id'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if ($request->filled('marketing_consent_status')) {
            $query->where('marketing_consent_status', $request->string('marketing_consent_status'));
        }
    }

    private function guardManager($authUser): ?JsonResponse
    {
        if ($authUser && in_array($authUser->role, ['company_admin', 'admin', 'location_manager'], true)) {
            return null;
        }
        return $this->forbidden('Only location managers and admins can perform this action.');
    }

    private function guardAdmin($authUser): ?JsonResponse
    {
        if ($authUser && in_array($authUser->role, ['company_admin', 'admin'], true)) {
            return null;
        }
        return $this->forbidden('Only admins can perform this action.');
    }

    private function guardPrintExport($authUser): ?JsonResponse
    {
        if (!$authUser) {
            return $this->forbidden();
        }
        if (in_array($authUser->role, ['company_admin', 'admin'], true)) {
            return null;
        }
        if ($authUser->role === 'location_manager'
            && WaiverSetting::forCompany($authUser->company_id)->manager_print_export_enabled) {
            return null;
        }
        return $this->forbidden('You do not have permission to print or export waivers.');
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

    private function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
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
