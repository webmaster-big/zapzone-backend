<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\EmailNotification;
use App\Models\Waiver;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverTemplate;
use App\Services\EmailNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WaiverBulkInviteController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = WaiverBulkInvite::with(['template:id,title', 'location:id,name'])
            ->withCount([
                'recipients',
                'recipients as complete_count' => fn ($q) => $q->where('status', 'complete'),
            ]);
        $this->applyAuthScope($query, $request);

        if ($request->filled('event_id')) {
            $query->where('event_id', $request->integer('event_id'));
        }
        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->integer('booking_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('selected_date', [$request->date('start_date'), $request->date('end_date')]);
        } elseif ($request->filled('date')) {
            $query->whereDate('selected_date', $request->date('date'));
        }
        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('chaperone_name', 'like', $like)
                        ->orWhere('chaperone_email', 'like', $like)
                        ->orWhere('chaperone_phone', 'like', $like);
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }
        }

        $sortBy = $request->string('sort_by')->toString();
        $sortOrder = strtolower($request->string('sort_order')->toString());
        $sortOrder = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'desc';
        $sortable = ['created_at', 'selected_date', 'chaperone_name', 'status'];
        if (in_array($sortBy, $sortable, true)) {
            $query->orderBy($sortBy, $sortOrder)->orderByDesc('id');
        } else {
            $query->orderByDesc('created_at');
        }

        $invites = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => [
                'bulk_invites' => $invites->items(),
                'pagination' => $this->paginationMeta($invites),
            ],
        ]);
    }

    public function show(WaiverBulkInvite $waiverBulkInvite): JsonResponse
    {
        if (!$this->authorizeRecordScope($waiverBulkInvite)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => $waiverBulkInvite->load([
                'template:id,title',
                'location:id,name',
                'recipients',
                'creator:id,first_name,last_name',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardManager($authUser)) {
            return $guard;
        }

        $validated = $request->validate([
            'waiver_template_id' => 'required|exists:waiver_templates,id',
            'selected_date' => 'required|date',
            'location_id' => 'nullable|exists:locations,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'event_id' => 'nullable|exists:events,id',
            'chaperone_name' => 'required|string|max:255',
            'chaperone_email' => 'nullable|email|max:255',
            'chaperone_phone' => 'nullable|string|max:30',
            'allow_shareable_link' => 'sometimes|boolean',
        ]);

        $template = WaiverTemplate::findOrFail($validated['waiver_template_id']);
        if (!$this->authorizeRecordScope($template)) {
            return $this->forbidden();
        }

        if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $validated['location_id'] = $authUser->location_id;

            if (!empty($validated['booking_id'])) {
                $booking = \App\Models\Booking::find($validated['booking_id']);
                if ($booking && (int) $booking->location_id !== (int) $authUser->location_id) {
                    return $this->forbidden('This booking belongs to a different location.');
                }
            }
            if (!empty($validated['event_id'])) {
                $event = \App\Models\Event::find($validated['event_id']);
                if ($event && (int) $event->location_id !== (int) $authUser->location_id) {
                    return $this->forbidden('This event belongs to a different location.');
                }
            }
        }

        $invite = WaiverBulkInvite::create([
            'company_id' => $template->company_id,
            'location_id' => $validated['location_id'] ?? $template->location_id,
            'booking_id' => $validated['booking_id'] ?? null,
            'event_id' => $validated['event_id'] ?? null,
            'waiver_template_id' => $template->id,
            'selected_date' => $validated['selected_date'],
            'chaperone_name' => $validated['chaperone_name'],
            'chaperone_email' => $validated['chaperone_email'] ?? null,
            'chaperone_phone' => $validated['chaperone_phone'] ?? null,
            'allow_shareable_link' => (bool) ($validated['allow_shareable_link'] ?? false),
            'status' => WaiverBulkInvite::STATUS_SENT,
            'created_by' => $authUser->id,
        ]);

        $this->notifyChaperone($invite);

        return response()->json([
            'success' => true,
            'message' => 'Bulk waiver invite sent to chaperone',
            'data' => $invite->fresh(),
        ], 201);
    }

    /** Resend the management link to the chaperone. */
    public function resend(Request $request, WaiverBulkInvite $waiverBulkInvite): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if ($guard = $this->guardManager($authUser)) {
            return $guard;
        }
        if (!$this->authorizeRecordScope($waiverBulkInvite)) {
            return $this->forbidden();
        }

        $this->notifyChaperone($waiverBulkInvite);

        return response()->json(['success' => true, 'message' => 'Chaperone invite resent']);
    }

    // ---- helpers ----

    /**
     * Notify the chaperone with their management link. We reuse the waiver notification
     * engine by handing it a lightweight pending waiver whose recipient is the chaperone
     * and whose link points at the bulk management page.
     */
    private function notifyChaperone(WaiverBulkInvite $invite): void
    {
        if (!$invite->chaperone_email && !$invite->chaperone_phone) {
            return;
        }

        try {
            // A transient waiver carries the chaperone's contact + the manage link for
            // the notification's {{waiver_link}}/{{customer_*}} variables. Not persisted.
            $proxy = new Waiver([
                'company_id' => $invite->company_id,
                'location_id' => $invite->location_id,
                'waiver_template_id' => $invite->waiver_template_id,
                'selected_date' => $invite->selected_date,
                'adult_first_name' => $invite->chaperone_name,
                'adult_email' => $invite->chaperone_email,
                'adult_phone' => $invite->chaperone_phone,
                'status' => Waiver::STATUS_PENDING,
            ]);
            $proxy->access_token = 'bulk/' . $invite->manage_token; // signing_url -> /waiver/bulk/<token>
            $proxy->setRelation('company', $invite->company);
            $proxy->setRelation('location', $invite->location);

            app(EmailNotificationService::class)
                ->triggerWaiverNotification($proxy, EmailNotification::TRIGGER_WAIVER_BULK_CHAPERONE);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify chaperone for bulk waiver invite', [
                'bulk_invite_id' => $invite->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function guardManager($authUser): ?JsonResponse
    {
        if ($authUser && in_array($authUser->role, ['company_admin', 'admin', 'location_manager'], true)) {
            return null;
        }
        return $this->forbidden('Only location managers and admins can send bulk waiver invites.');
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
}
