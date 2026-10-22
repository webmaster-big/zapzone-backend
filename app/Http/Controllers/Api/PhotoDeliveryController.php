<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\PresentsPhotos;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\PhotoDelivery;
use App\Services\PhotoDeliveryService;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhotoDeliveryController extends Controller
{
    use ScopesByAuthUser, PresentsPhotos;

    public function __construct(protected PhotoDeliveryService $deliveries)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', Rule::in([
                PhotoDelivery::STATUS_QUEUED,
                PhotoDelivery::STATUS_SCHEDULED,
                PhotoDelivery::STATUS_SENT,
                PhotoDelivery::STATUS_FAILED,
                PhotoDelivery::STATUS_CANCELED,
                PhotoDelivery::STATUS_SKIPPED,
            ])],
            'channel' => ['nullable', Rule::in([PhotoDelivery::CHANNEL_EMAIL, PhotoDelivery::CHANNEL_SMS])],
            'kind' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_duplicates' => ['nullable', 'boolean'],
            'outcome' => ['nullable', Rule::in(['not_delivered', 'awaiting_carrier', 'reached_phone'])],
        ]);

        if ($request->filled('location_id')
            && ($denied = $this->guardLocationAccess($request, $request->integer('location_id')))) {
            return $denied;
        }

        $query = PhotoDelivery::with(array_merge(
            ['session.location', 'waiver', 'creator', 'receiptConfirmer'],
            \App\Models\EscapeRoomSession::isAvailable() ? ['session.escapeRoomSession.package:id,name'] : []
        ))->latest();
        $this->applyAuthScope($query, $request);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }
        if (!$request->boolean('include_duplicates')) {
            $query->real();
        }
        if ($request->filled('outcome')) {
            $query->where('channel', PhotoDelivery::CHANNEL_SMS)->where('status', PhotoDelivery::STATUS_SENT);

            match ($request->input('outcome')) {
                'not_delivered' => $query->whereIn('provider_status', \App\Services\SmsService::NOT_DELIVERED_STATUSES),
                'awaiting_carrier' => $query->awaitingCarrier()->whereNull('receipt_confirmed_at'),
                default => $query
                    ->where(function ($q) {
                        $q->whereNull('provider_status')->orWhereNotIn('provider_status', \App\Services\SmsService::NOT_DELIVERED_STATUSES);
                    })
                    ->where(function ($q) {
                        $q->whereNotNull('receipt_confirmed_at')->orWhereIn('provider_status', ['delivered', 'read']);
                    }),
            };
        }

        DateRange::apply($query, 'photo_deliveries.created_at', $request->input('from'), $request->input('to'));

        $page = $query->paginate(min(100, $request->integer('per_page') ?: 30));

        return response()->json([
            'success' => true,
            'data' => $page->through(fn (PhotoDelivery $delivery) => array_merge($this->presentDelivery($delivery), [
                'location_name' => $delivery->session?->location?->name,
                'session_source' => $delivery->session?->source,
                'session_delivery_status' => $delivery->session?->deliveryStatus(),
                'photo_link' => $delivery->session ? $this->sessionPhotoLink($delivery->session) : null,
                'sent_by_name' => $delivery->creator
                    ? trim(($delivery->creator->first_name ?? '') . ' ' . ($delivery->creator->last_name ?? ''))
                    : null,
            ])),
        ]);
    }

    public function retry(Request $request, PhotoDelivery $photoDelivery): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoDelivery)) {
            return $this->forbidden();
        }
        if ($photoDelivery->isDuplicate()) {
            return response()->json([
                'success' => false,
                'message' => 'This row records a link whose destination was already sent to. Retry the original instead.',
            ], 422);
        }
        if (!$photoDelivery->canRetry()) {
            return response()->json([
                'success' => false,
                'message' => 'Only sent or failed deliveries can be retried.',
            ], 422);
        }

        $sent = $this->deliveries->retry($photoDelivery, $this->resolveAuthUser($request)?->id);
        $fresh = $photoDelivery->fresh();

        return response()->json([
            'success' => $sent,
            'message' => $sent
                ? ($fresh->isText()
                    ? 'The text provider accepted it. The row shows when the phone carrier confirms it reached the phone.'
                    : 'Email sent.')
                : 'It did not go out again: ' . ($fresh->outcomeDetail() ?? 'check the reason on the row.'),
            'data' => $this->presentDelivery($fresh),
        ], $sent ? 200 : 422);
    }

    public function refreshStatus(Request $request): JsonResponse
    {
        if ($limited = $this->tooManyLookups($request, 'status', 120)) {
            return $limited;
        }

        $validated = $request->validate([
            'delivery_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'delivery_ids.*' => ['integer'],
        ]);

        $query = PhotoDelivery::whereIn('id', array_unique($validated['delivery_ids']));
        $this->applyAuthScope($query, $request);
        $deliveries = $query->orderBy('id')->get();

        $deliveries = (new \Illuminate\Database\Eloquent\Collection($this->deliveries->refreshAwaiting($deliveries)->all()))
            ->load('receiptConfirmer');
        $companyId = $deliveries->first()?->company_id;

        return response()->json([
            'success' => true,
            'data' => [
                'deliveries' => $deliveries->map(fn (PhotoDelivery $delivery) => $this->presentDelivery($delivery))->values(),
                'outcome' => $deliveries->isEmpty() ? null : $this->deliveries->summarizeOutcome($deliveries, $companyId),
            ],
        ]);
    }

    public function confirmReceipt(Request $request, PhotoDelivery $photoDelivery): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoDelivery)) {
            return $this->forbidden();
        }

        if ($limited = $this->tooManyLookups($request, 'confirm', 30)) {
            return $limited;
        }

        if (!$photoDelivery->isText()) {
            return response()->json([
                'success' => false,
                'message' => 'Only a text message can be confirmed as received on the guest\'s phone.',
            ], 422);
        }
        if ($photoDelivery->receipt_confirmed_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'This text was already confirmed as received.',
                'data' => $this->presentDelivery($photoDelivery->load('receiptConfirmer')),
            ]);
        }

        if ($photoDelivery->provider_sid !== null && !\App\Services\SmsService::isFinalStatus($photoDelivery->provider_status)) {
            $photoDelivery = $this->deliveries->refreshCarrierStatus($photoDelivery, true);
        }

        $refusal = match (true) {
            $photoDelivery->status === PhotoDelivery::STATUS_SENT && $photoDelivery->provider_sid === null
                => 'This text was sent before delivery tracking was added, so it cannot be confirmed now.',
            $photoDelivery->carrierSaysNotDelivered()
                => 'The phone carrier reported this text as not delivered, so it cannot be marked as received. '
                    . match (true) {
                        !$photoDelivery->retryIsUseful() => 'Sending it again to this number will not help.',
                        $this->deliveries->blockedNow($photoDelivery) => 'Sending it again will not help while texts from this number are blocked.',
                        default => 'Send it again with Retry (Resend in the delivery log).',
                    },
            $photoDelivery->isStillWithProvider()
                => 'The phone carrier has not received this text yet, so it cannot be on the guest\'s phone. Wait a few seconds and try again.',
            $photoDelivery->status === PhotoDelivery::STATUS_SENT && !$photoDelivery->isAwaitingCarrier() && !$photoDelivery->carrierSaysDelivered()
                => 'This text was sent more than ' . PhotoDelivery::CARRIER_CHECK_HOURS . ' hours ago and the phone carrier never confirmed it, so it can no longer be recorded as received. To confirm texting, send a new photo text while the guest is here.',
            !$photoDelivery->canConfirmReceipt()
                => 'Only a text that was sent can be confirmed as received.',
            default => null,
        };

        $result = $refusal === null
            ? $this->deliveries->confirmReceipt($photoDelivery, $this->resolveAuthUser($request))
            : ['recorded' => false, 'verified' => false, 'delivery' => $photoDelivery];

        if (!$result['recorded']) {
            $current = $result['delivery']->fresh() ?? $result['delivery'];

            return response()->json([
                'success' => false,
                'message' => $refusal ?? 'That text can no longer be confirmed. The phone carrier may have just reported it as not delivered.',
                'data' => $this->presentDelivery($current->load('receiptConfirmer')),
            ], 422);
        }

        $delivery = $result['delivery']->fresh('receiptConfirmer');
        $texting = $this->deliveries->textingStatus($delivery->company_id, false);

        return response()->json([
            'success' => true,
            'message' => match (true) {
                $result['verified'] && $texting['state'] === 'verified' => 'Thanks. Texting is now confirmed working for your venues.',
                !$this->deliveries->receiptCanVerify($delivery) => 'Thanks. This text is recorded as received. It was sent before texting was last reset, or from an earlier texting number, so it does not confirm texting. Send a new photo text to confirm.',
                $texting['state'] === 'verified' || $delivery->carrierSaysDelivered() => 'Thanks. This text is recorded as received on the guest\'s phone.',
                default => 'Thanks. This text is recorded as received on the guest\'s phone. Texting shows as confirmed working once the phone carrier reports it delivered, or after '
                    . PhotoDelivery::CARRIER_CHECK_HOURS . ' hours if the carrier reports no problem.',
            },
            'data' => $this->presentDelivery($delivery),
            'texting' => $texting,
        ]);
    }

    protected function tooManyLookups(Request $request, string $action, int $perMinute): ?JsonResponse
    {
        $key = 'photo-carrier-' . $action . ':' . ($this->resolveAuthUser($request)?->id ?? $request->ip());

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, $perMinute)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many checks in a short time. Wait a minute and try again.',
            ], 429);
        }

        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        return null;
    }

    public function cancel(Request $request, PhotoDelivery $photoDelivery): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoDelivery)) {
            return $this->forbidden();
        }
        if (!$photoDelivery->canCancel()) {
            return response()->json([
                'success' => false,
                'message' => 'Only a scheduled delivery can be canceled.',
            ], 422);
        }

        $this->deliveries->cancel($photoDelivery, $this->resolveAuthUser($request)?->id);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled delivery canceled.',
            'data' => $this->presentDelivery($photoDelivery->fresh()),
        ]);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have access to that delivery.',
        ], 403);
    }
}
