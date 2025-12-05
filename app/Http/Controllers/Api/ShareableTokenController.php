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
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        // Try to get authenticated user for tracking purposes
        $user = $request->user();

        // Set created_by if user is authenticated, otherwise null
        $validated['created_by'] = $user ? $user->id : null;

        // Validate location_id is required for certain roles
        if (in_array($validated['role'], ['location_manager', 'attendant']) && empty($validated['location_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Location ID is required for location_manager and attendant roles',
            ], 422);
        }

        $token = ShareableToken::create($validated);

        // Log token creation
        Log::info('Shareable token created', [
            'email' => $validated['email'],
            'token_id' => $token->id,
            'created_by' => $validated['created_by'],
            'is_public' => !$user,
        ]);
            'token_id' => $token->id,
            'created_by' => $user->id,
        ]);

        // Return immediately without sending email to avoid timeout
        // Email can be sent via a separate queue worker or cron job later
        return response()->json([
            'success' => true,
            'message' => 'Token created successfully',
            'data' => [
                'link' => $token->getShareableLink(),
                'email' => $validated['email'],
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
