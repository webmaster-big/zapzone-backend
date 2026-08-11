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
        ]);

        if ($request->filled('location_id')
            && ($denied = $this->guardLocationAccess($request, $request->integer('location_id')))) {
            return $denied;
        }

        $query = PhotoDelivery::with(['session.location', 'waiver', 'creator'])->latest();
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
                'message' => 'This row records a waiver link whose destination was already sent to. Retry the original instead.',
            ], 422);
        }
        if (!$photoDelivery->canRetry()) {
            return response()->json([
                'success' => false,
                'message' => 'Only sent or failed deliveries can be retried.',
            ], 422);
        }

        $sent = $this->deliveries->retry($photoDelivery, $this->resolveAuthUser($request)?->id);

        return response()->json([
            'success' => $sent,
            'message' => $sent ? 'Delivery sent.' : 'The delivery failed again. Check the error on the row.',
            'data' => $this->presentDelivery($photoDelivery->fresh()),
        ], $sent ? 200 : 422);
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
