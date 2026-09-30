<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitFollowUp;
use App\Services\VisitFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitFeedbackController extends Controller
{
    public function __construct(protected VisitFollowUpService $service)
    {
    }

    public function show(string $token): JsonResponse
    {
        if ($optOut = $this->service->parseOptOutToken($token)) {
            return response()->json(['success' => true, 'data' => $this->service->optOutPayload($optOut)]);
        }

        $row = $this->service->findByToken($token);

        if (!$row) {
            return $this->notFound();
        }

        return response()->json(['success' => true, 'data' => $this->service->publicPayload($row)]);
    }

    public function rate(Request $request, string $token): JsonResponse
    {
        $row = $this->service->findByToken($token);

        if (!$row) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:' . VisitFollowUpService::MAX_COMMENT_LENGTH],
        ], [
            'rating.required' => 'Choose a rating from 1 to 5 stars.',
            'rating.between' => 'Choose a rating from 1 to 5 stars.',
        ]);

        $this->service->rate($row, (int) $validated['rating'], $request->exists('comment') ? (string) ($validated['comment'] ?? '') : null);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your feedback!',
            'data' => $this->service->publicPayload($row->fresh()),
        ]);
    }

    public function unsubscribe(string $token): JsonResponse
    {
        if ($optOut = $this->service->parseOptOutToken($token)) {
            $this->service->unsubscribeEmail($optOut['company_id'], $optOut['email']);

            return response()->json([
                'success' => true,
                'message' => 'You will not get any more review requests or offers from us.',
                'data' => $this->service->optOutPayload($optOut),
            ]);
        }

        $row = $this->service->findByToken($token);

        if (!$row) {
            return $this->notFound();
        }

        $this->service->unsubscribe($row);

        return response()->json([
            'success' => true,
            'message' => 'You will not get any more review requests or offers from us.',
            'data' => $this->service->publicPayload($row->fresh()),
        ]);
    }

    protected function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This link is not valid any more.',
        ], 404);
    }
}
