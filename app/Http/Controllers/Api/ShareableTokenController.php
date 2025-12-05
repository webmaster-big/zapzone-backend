<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareableToken;
use App\Mail\ShareableTokenMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class ShareableTokenController extends Controller
{
    /**
     * Create and send shareable token via email.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(['company_admin', 'location_manager', 'attendant'])],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validated['created_by'] = $user->id;
        $validated['company_id'] = $user->company_id;

        // Only include location_id for location_manager and attendant roles
        if (in_array($validated['role'], ['location_manager', 'attendant'])) {
            $validated['location_id'] = $user->location_id;
        }

        $token = ShareableToken::create($validated);

        // Send email asynchronously to avoid blocking the response
        dispatch(function () use ($validated, $token) {
            try {
                Mail::to($validated['email'])->send(new ShareableTokenMail($token));
            } catch (\Exception $e) {
                \Log::error('Failed to send shareable token email: ' . $e->getMessage());
            }
        })->afterResponse();

        return response()->json([
            'success' => true,
            'message' => 'Token created successfully',
            'data' => [
                'link' => $token->getShareableLink(),
            ],
        ], 201);
    }

    /**
     * Check if token is valid and not used.
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $token = ShareableToken::where('token', $validated['token'])->first();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token',
            ], 404);
        }

        if ($token->isUsed()) {
            return response()->json([
                'success' => false,
                'message' => 'Token already used',
                'used_at' => $token->used_at,
            ], 400);
        }

        if (!$token->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Token inactive',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token is valid',
            'data' => [
                'email' => $token->email,
                'role' => $token->role,
                'company_id' => $token->company_id,
                'location_id' => $token->location_id,
            ],
        ]);
    }
}
