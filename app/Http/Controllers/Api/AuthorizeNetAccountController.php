<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuthorizeNetAccount;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AuthorizeNetAccountController extends Controller
{

    public function allAccounts(Request $request)
    {
        $accounts = AuthorizeNetAccount::with('location')->get();

        return response()->json([
            'success' => true,
            'data' => $accounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'location_id' => $account->location_id,
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                    'connected_at' => $account->connected_at,
                    'last_tested_at' => $account->last_tested_at,
                    'location' => [
                        'id' => $account->location->id,
                        'name' => $account->location->name,
                        'city' => $account->location->city,
                        'state' => $account->location->state,
                    ],
                ];
            }),
        ]);
    }

    /**
     * Get the Authorize.Net account for the authenticated user's location
     */
    public function show(Request $request)
    {
        $user = $request->user();

        // Ensure user has a location assigned
        if (!$user->location_id) {
            return response()->json([
                'message' => 'No location assigned to your account'
            ], 403);
        }

        // Get the account for the user's location
        $account = AuthorizeNetAccount::where('location_id', $user->location_id)->first();

        if (!$account) {
            return response()->json([
                'message' => 'No Authorize.Net account connected',
                'connected' => false
            ], 200);
        }

        // Check if credentials can be decrypted
        $credentialsValid = true;
        try {
            // Attempt to access encrypted field to verify APP_KEY matches
            $testAccess = $account->api_login_id;
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            $credentialsValid = false;
            Log::warning('Authorize.Net credentials cannot be decrypted - APP_KEY mismatch', [
                'location_id' => $user->location_id,
                'account_id' => $account->id
            ]);
        }

        return response()->json([
            'connected' => true,
            'credentials_valid' => $credentialsValid,
            'account' => [
                'id' => $account->id,
                'location_id' => $account->location_id,
                'environment' => $account->environment,
                'is_active' => $account->is_active,
                'connected_at' => $account->connected_at,
                'last_tested_at' => $account->last_tested_at,
                // Note: We never expose the actual credentials
            ]
        ]);
    }

    /**
     * Connect a new Authorize.Net account (store)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Ensure user has a location assigned
        if (!$user->location_id) {
            return response()->json([
                'message' => 'No location assigned to your account'
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'api_login_id' => 'required|string|max:255',
            'transaction_key' => 'required|string|max:255',
            'environment' => 'required|in:sandbox,production',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if account already exists for this location
        $existingAccount = AuthorizeNetAccount::where('location_id', $user->location_id)->first();

        if ($existingAccount) {
            return response()->json([
                'message' => 'An Authorize.Net account is already connected to this location. Please disconnect it first.'
            ], 409);
        }

        try {
            // Create the account
            $account = AuthorizeNetAccount::create([
                'location_id' => $user->location_id,
                'api_login_id' => $request->api_login_id,
                'transaction_key' => $request->transaction_key,
                'environment' => $request->environment,
                'is_active' => true,
                'connected_at' => now(),
            ]);

            Log::info('Authorize.Net account connected', [
                'location_id' => $user->location_id,
                'environment' => $request->environment,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Authorize.Net account connected successfully',
                'account' => [
                    'id' => $account->id,
                    'location_id' => $account->location_id,
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                    'connected_at' => $account->connected_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to connect Authorize.Net account', [
                'location_id' => $user->location_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to connect Authorize.Net account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the Authorize.Net account
     */
    public function update(Request $request)
    {
        $user = $request->user();

        if (!$user->location_id) {
            return response()->json([
                'message' => 'No location assigned to your account'
            ], 403);
        }

        $account = AuthorizeNetAccount::where('location_id', $user->location_id)->first();

        if (!$account) {
            return response()->json([
                'message' => 'No Authorize.Net account found for this location'
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'api_login_id' => 'sometimes|required|string|max:255',
            'transaction_key' => 'sometimes|required|string|max:255',
            'environment' => 'sometimes|required|in:sandbox,production',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $account->update($request->only([
                'api_login_id',
                'transaction_key',
                'environment',
                'is_active'
            ]));

            Log::info('Authorize.Net account updated', [
                'location_id' => $user->location_id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Authorize.Net account updated successfully',
                'account' => [
                    'id' => $account->id,
                    'location_id' => $account->location_id,
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                    'connected_at' => $account->connected_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update Authorize.Net account', [
                'location_id' => $user->location_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to update Authorize.Net account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disconnect (delete) the Authorize.Net account
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        if (!$user->location_id) {
            return response()->json([
                'message' => 'No location assigned to your account'
            ], 403);
        }

        $account = AuthorizeNetAccount::where('location_id', $user->location_id)->first();

        if (!$account) {
            return response()->json([
                'message' => 'No Authorize.Net account found for this location'
            ], 404);
        }

        try {
            $locationId = $account->location_id;
            $accountId = $account->id;

            $account->delete();

            // Log account deletion
            ActivityLog::log(
                action: 'Authorize.Net Account Deleted',
                category: 'delete',
                description: 'Authorize.Net account disconnected',
                userId: $user->id,
                locationId: $locationId,
                entityType: 'authorize_net_account',
                entityId: $accountId
            );

            Log::info('Authorize.Net account disconnected', [
                'location_id' => $user->location_id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Authorize.Net account disconnected successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to disconnect Authorize.Net account', [
                'location_id' => $user->location_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to disconnect Authorize.Net account',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get public API credentials for frontend (Accept.js)
     * Only returns API Login ID - NEVER the transaction key
     */
    public function getPublicKey(Request $request, $locationId)
    {
        try {
            $account = AuthorizeNetAccount::where('location_id', $locationId)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json([
                    'message' => 'No active Authorize.Net account found for this location'
                ], 404);
            }

            // Try to decrypt the API login ID
            try {
                $apiLoginId = $account->api_login_id;
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                Log::error('Failed to decrypt Authorize.Net credentials - APP_KEY mismatch', [
                    'location_id' => $locationId,
                    'account_id' => $account->id,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'message' => 'Payment configuration is corrupted. Please reconnect your Authorize.Net account.',
                    'error' => 'Encryption key mismatch - credentials need to be re-entered'
                ], 500);
            }

            // Only return API Login ID for Accept.js
            // NEVER expose the transaction key to frontend
            return response()->json([
                'api_login_id' => $apiLoginId,
                'environment' => $account->environment,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve public key', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve payment configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
