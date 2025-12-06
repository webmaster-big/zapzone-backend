<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareableToken;
use App\Mail\ShareableTokenMail;
use App\Services\GmailApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ShareableTokenController extends Controller
{
    /**
     * Create and send shareable token via email.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email|max:255',
                'role' => ['required', Rule::in(['company_admin', 'location_manager', 'attendant'])],
                'company_id' => 'nullable|exists:companies,id',
                'location_id' => 'nullable|exists:locations,id',
            ]);

            // Try to get authenticated user for tracking purposes
            $user = $request->user();

            // Set created_by if user is authenticated, otherwise null
            $validated['created_by'] = $user ? $user->id : null;

            // If no company_id provided, try to get from user, otherwise use first company
            if (empty($validated['company_id'])) {
                if ($user && $user->company_id) {
                    $validated['company_id'] = $user->company_id;
                } else {
                    // Get first company as fallback
                    $firstCompany = \App\Models\Company::first();
                    if (!$firstCompany) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No company found in system',
                        ], 422);
                    }
                    $validated['company_id'] = $firstCompany->id;
                }
            }

            // Validate location_id is required for certain roles
            if (in_array($validated['role'], ['attendant']) && empty($validated['location_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location ID is required for attendant roles',
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

            // Send email using Gmail API or SMTP
            try {
                Log::info('Preparing to send shareable token email', [
                    'email' => $validated['email'],
                    'token_id' => $token->id,
                    'use_gmail_api' => config('gmail.enabled', false),
                ]);

                // Check if Gmail API should be used
                $useGmailApi = config('gmail.enabled', false) && 
                              (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

                if ($useGmailApi) {
                    Log::info('Using Gmail API for shareable token email', [
                        'token_id' => $token->id,
                    ]);

                    $gmailService = new GmailApiService();
                    $mailable = new ShareableTokenMail($token);
                    $emailBody = $mailable->render();

                    $gmailService->sendEmail(
                        $validated['email'],
                        'Registration Invitation - Zap Zone',
                        $emailBody,
                        'Zap Zone'
                    );
                } else {
                    Log::info('Using Laravel Mail (SMTP) for shareable token email', [
                        'token_id' => $token->id,
                        'mail_driver' => config('mail.default'),
                    ]);

                    // Send using Laravel Mail (SMTP)
                    \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($token, $validated) {
                        $mailable = new ShareableTokenMail($token);
                        $emailBody = $mailable->render();

                        $message->to($validated['email'])
                            ->subject('Registration Invitation - Zap Zone')
                            ->html($emailBody);
                    });
                }

                Log::info('✅ Shareable token email sent successfully', [
                    'email' => $validated['email'],
                    'token_id' => $token->id,
                    'method' => $useGmailApi ? 'Gmail API' : 'SMTP',
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Failed to send shareable token email', [
                    'email' => $validated['email'],
                    'token_id' => $token->id,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue even if email fails - don't block token creation
            }

            // Return response
            return response()->json([
                'success' => true,
                'message' => 'Token created and email sent successfully',
                'data' => [
                    'link' => $token->getShareableLink(),
                    'email' => $validated['email'],
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating shareable token: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create token: ' . $e->getMessage(),
            ], 500);
        }
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
