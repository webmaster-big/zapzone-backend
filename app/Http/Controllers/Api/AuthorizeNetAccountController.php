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
                    'id'            => $account->id,
                    'location_id'   => $account->location_id,
                    'label'         => $account->label,
                    'environment'   => $account->environment,
                    'is_active'     => $account->is_active,
                    'connected_at'  => $account->connected_at,
                    'last_tested_at'=> $account->last_tested_at,
                    'location'      => $account->location ? [
                        'id'    => $account->location->id,
                        'name'  => $account->location->name,
                        'city'  => $account->location->city,
                        'state' => $account->location->state,
                    ] : null,
                ];
            }),
        ]);
    }

    public function show(Request $request)
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
                'message' => 'No Authorize.Net account connected',
                'connected' => false
            ], 200);
        }

        $credentialsValid = true;
        try {
            $testAccess = $account->api_login_id;
            Log::info('Authorize.Net credentials decrypted successfully', [
                'location_id' => $user->location_id,
                'account_id' => $account->id,
                'environment' => $account->environment
            ]);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            $credentialsValid = false;
            Log::error('Authorize.Net credentials decryption failed - APP_KEY mismatch', [
                'location_id' => $user->location_id,
                'account_id' => $account->id,
                'environment' => $account->environment,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'possible_cause' => 'APP_KEY environment variable changed since credentials were stored',
                'solution' => 'Run: php artisan authorizenet:re-encrypt or reconnect account',
                'connected_at' => $account->connected_at,
                'current_app_key_prefix' => substr(config('app.key'), 0, 20) . '...'
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
            ]
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $isCompanyAdmin = $user->role === 'company_admin';

        // company_admin: may pass location_id (or omit for a centralized account with no location).
        // location_manager/attendant: must use their own location_id.
        if ($isCompanyAdmin) {
            $targetLocationId = $request->filled('location_id') ? (int) $request->location_id : null;
        } else {
            if (!$user->location_id) {
                return response()->json(['success' => false, 'message' => 'No location assigned to your account.'], 403);
            }
            $targetLocationId = (int) $user->location_id;
        }

        $validator = Validator::make($request->all(), [
            'api_login_id'      => 'required|string|max:255',
            'transaction_key'   => 'required|string|max:255',
            'public_client_key' => 'required|string|max:1000',
            'environment'       => 'required|in:sandbox,production',
            'label'             => 'nullable|string|max:100',
            'location_id'       => 'nullable|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Prevent duplicate per-location accounts
        if ($targetLocationId !== null) {
            $existingAccount = AuthorizeNetAccount::where('location_id', $targetLocationId)->first();
            if ($existingAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'An Authorize.Net account is already connected to this location. Please disconnect it first.',
                ], 409);
            }
        }

        try {
            $account = AuthorizeNetAccount::create([
                'location_id'       => $targetLocationId,
                'label'             => $targetLocationId === null ? trim($request->label ?? 'Centralized Account') : null,
                'api_login_id'      => trim($request->api_login_id),
                'transaction_key'   => trim($request->transaction_key),
                'public_client_key' => trim($request->public_client_key),
                'environment'       => $request->environment,
                'is_active'         => true,
                'connected_at'      => now(),
            ]);

            Log::info('Authorize.Net account connected', [
                'location_id'  => $targetLocationId,
                'centralized'  => $targetLocationId === null,
                'label'        => $account->label,
                'environment'  => $request->environment,
                'user_id'      => $user->id,
                'account_id'   => $account->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Authorize.Net account connected successfully',
                'data' => [
                    'id'           => $account->id,
                    'location_id'  => $account->location_id,
                    'label'        => $account->label,
                    'environment'  => $account->environment,
                    'is_active'    => $account->is_active,
                    'connected_at' => $account->connected_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to connect Authorize.Net account', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            return response()->json(['success' => false, 'message' => 'Failed to connect Authorize.Net account', 'error' => $e->getMessage()], 500);
        }
    }

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

        $validator = Validator::make($request->all(), [
            'api_login_id' => 'sometimes|required|string|max:255',
            'transaction_key' => 'sometimes|required|string|max:255',
            'public_client_key' => 'sometimes|required|string|max:1000',
            'environment' => 'sometimes|required|in:sandbox,production',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            Log::warning('Authorize.Net account update validation failed', [
                'location_id' => $user->location_id,
                'user_id' => $user->id,
                'account_id' => $account->id,
                'errors' => $validator->errors()->toArray(),
                'provided_fields' => array_keys($request->all())
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updateData = $request->only([
                'api_login_id',
                'transaction_key',
                'public_client_key',
                'environment',
                'is_active'
            ]);

            if (isset($updateData['api_login_id'])) {
                $updateData['api_login_id'] = trim($updateData['api_login_id']);
            }
            if (isset($updateData['transaction_key'])) {
                $updateData['transaction_key'] = trim($updateData['transaction_key']);
            }
            if (isset($updateData['public_client_key'])) {
                $updateData['public_client_key'] = trim($updateData['public_client_key']);
            }

            Log::info('Updating Authorize.Net account', [
                'location_id' => $user->location_id,
                'user_id' => $user->id,
                'account_id' => $account->id,
                'fields_updating' => array_keys($updateData),
                'environment_change' => $request->has('environment') ? $account->environment . ' -> ' . $request->environment : 'no change'
            ]);

            $account->update($updateData);

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
                'user_id' => $user->id,
                'account_id' => $account->id,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'attempted_fields' => array_keys($request->only(['api_login_id', 'transaction_key', 'environment', 'is_active'])),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to update Authorize.Net account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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

            ActivityLog::log(
                action: 'Authorize.Net Account Deleted',
                category: 'delete',
                description: 'Authorize.Net account disconnected',
                userId: $user->id,
                locationId: $locationId,
                entityType: 'authorize_net_account',
                entityId: $accountId,
                metadata: [
                    'disconnected_by' => [
                        'user_id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'email' => $user->email,
                    ],
                    'disconnected_at' => now()->toIso8601String(),
                    'account_details' => [
                        'account_id' => $accountId,
                        'location_id' => $locationId,
                    ],
                ]
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
                'user_id' => $user->id,
                'account_id' => $account->id ?? null,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to disconnect Authorize.Net account',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getPublicKey(Request $request, $locationId)
    {
        Log::info('🔑 Public key request received', [
            'location_id' => $locationId,
            'request_ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        try {
            $account = AuthorizeNetAccount::where('location_id', $locationId)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                Log::warning('❌ No active Authorize.Net account found', [
                    'location_id' => $locationId
                ]);
                return response()->json([
                    'message' => 'No active Authorize.Net account found for this location'
                ], 404);
            }

            Log::info('✅ Account found', [
                'account_id' => $account->id,
                'environment' => $account->environment,
                'is_active' => $account->is_active,
                'connected_at' => $account->connected_at
            ]);

            try {
                $apiLoginId = $account->api_login_id;
                $publicClientKey = $account->public_client_key;

                Log::info('✅ Public key retrieved successfully for Accept.js', [
                    'location_id' => $locationId,
                    'account_id' => $account->id,
                    'environment' => $account->environment,
                    'api_login_id_length' => strlen($apiLoginId),
                    'api_login_id_preview' => substr($apiLoginId, 0, 4) . '...' . substr($apiLoginId, -4),
                    'has_public_client_key' => !empty($publicClientKey),
                    'public_client_key_length' => $publicClientKey ? strlen($publicClientKey) : 0,
                    'account_is_active' => $account->is_active,
                    'connected_at' => $account->connected_at,
                    'response_will_contain' => [
                        'api_login_id' => 'length: ' . strlen($apiLoginId),
                        'client_key' => $publicClientKey ? 'length: ' . strlen($publicClientKey) : 'MISSING',
                        'environment' => $account->environment
                    ]
                ]);

                if (empty($publicClientKey)) {
                    Log::warning('⚠️ Public Client Key is missing for this account', [
                        'location_id' => $locationId,
                        'account_id' => $account->id,
                        'message' => 'Accept.js requires a Public Client Key. Please update the account with the Public Client Key from Authorize.Net dashboard.'
                    ]);
                }
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                Log::error('❌ Failed to decrypt Authorize.Net credentials - APP_KEY mismatch', [
                    'location_id' => $locationId,
                    'account_id' => $account->id,
                    'environment' => $account->environment,
                    'error_message' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'error_code' => $e->getCode(),
                    'connected_at' => $account->connected_at,
                    'last_tested_at' => $account->last_tested_at,
                    'possible_cause' => 'APP_KEY changed after credentials were encrypted',
                    'solution_1' => 'Run: php artisan authorizenet:re-encrypt',
                    'solution_2' => 'Disconnect and reconnect Authorize.Net account',
                    'current_app_key_prefix' => substr(config('app.key'), 0, 20) . '...'
                ]);

                return response()->json([
                    'message' => 'Payment configuration is corrupted. Please reconnect your Authorize.Net account.',
                    'error' => 'Encryption key mismatch - credentials need to be re-entered'
                ], 500);
            }

            return response()->json([
                'api_login_id' => $apiLoginId,
                'client_key' => $publicClientKey,
                'environment' => $account->environment,
                'accept_js_url' => $account->environment === 'production'
                    ? 'https://js.authorize.net/v1/Accept.js'
                    : 'https://jstest.authorize.net/v1/Accept.js',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve public key', [
                'location_id' => $locationId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'request_method' => request()->method(),
                'request_ip' => request()->ip()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve payment configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testConnection(Request $request)
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
            $apiLoginId = $account->api_login_id;
            $transactionKey = $account->transaction_key;

            Log::info('Testing Authorize.Net connection', [
                'location_id' => $user->location_id,
                'account_id' => $account->id,
                'environment' => $account->environment,
                'api_login_id_preview' => substr($apiLoginId, 0, 4) . '...',
                'api_login_id_length' => strlen($apiLoginId),
                'transaction_key_length' => strlen($transactionKey)
            ]);

            $merchantAuthentication = new \net\authorize\api\contract\v1\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            $apiRequest = new \net\authorize\api\contract\v1\GetMerchantDetailsRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);

            $environment = $account->environment === 'production'
                ? \net\authorize\api\constants\ANetEnvironment::PRODUCTION
                : \net\authorize\api\constants\ANetEnvironment::SANDBOX;

            $controller = new \net\authorize\api\controller\GetMerchantDetailsController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            Log::info('Authorize.Net test response', [
                'response_received' => $response !== null,
                'result_code' => $response ? $response->getMessages()->getResultCode() : 'null'
            ]);

            $account->update(['last_tested_at' => now()]);

            if ($response !== null && $response->getMessages()->getResultCode() === "Ok") {
                return response()->json([
                    'success' => true,
                    'message' => 'Credentials are valid',
                    'environment' => $account->environment,
                    'tested_at' => now(),
                    'debug' => config('app.debug') ? [
                        'merchant_name' => $response->getMerchantName(),
                        'gateway_id' => $response->getGatewayId(),
                    ] : null
                ]);
            } else {
                $errorMessages = [];
                if ($response !== null && $response->getMessages()->getMessage()) {
                    foreach ($response->getMessages()->getMessage() as $message) {
                        $errorMessages[] = $message->getCode() . ': ' . $message->getText();
                    }
                }

                Log::warning('Authorize.Net authentication test failed', [
                    'location_id' => $user->location_id,
                    'account_id' => $account->id,
                    'environment' => $account->environment,
                    'errors' => $errorMessages
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Authentication failed - credentials may be incorrect',
                    'environment' => $account->environment,
                    'tested_at' => now(),
                    'debug' => config('app.debug') ? ['errors' => $errorMessages] : null
                ]);
            }

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Cannot test - decryption failed', [
                'location_id' => $user->location_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credentials are encrypted with a different key. Please reconnect your account.',
                'credentials_valid' => false
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to test Authorize.Net connection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to test connection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testConnectionForLocation(Request $request, $locationId)
    {
        $account = AuthorizeNetAccount::where('location_id', $locationId)->first();

        if (!$account) {
            return response()->json([
                'message' => 'No Authorize.Net account found for this location'
            ], 404);
        }

        try {
            $apiLoginId = $account->api_login_id;
            $transactionKey = $account->transaction_key;

            Log::info('Testing Authorize.Net connection for location', [
                'location_id' => $locationId,
                'account_id' => $account->id,
                'environment' => $account->environment,
                'api_login_id_preview' => substr($apiLoginId, 0, 4) . '...' . substr($apiLoginId, -2),
                'api_login_id_length' => strlen($apiLoginId),
                'transaction_key_length' => strlen($transactionKey)
            ]);

            $merchantAuthentication = new \net\authorize\api\contract\v1\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            $apiRequest = new \net\authorize\api\contract\v1\GetMerchantDetailsRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);

            $environment = $account->environment === 'production'
                ? \net\authorize\api\constants\ANetEnvironment::PRODUCTION
                : \net\authorize\api\constants\ANetEnvironment::SANDBOX;

            $controller = new \net\authorize\api\controller\GetMerchantDetailsController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            Log::info('Authorize.Net test response for location', [
                'location_id' => $locationId,
                'response_received' => $response !== null,
                'result_code' => $response ? $response->getMessages()->getResultCode() : 'null'
            ]);

            $account->update(['last_tested_at' => now()]);

            if ($response !== null && $response->getMessages()->getResultCode() === "Ok") {
                return response()->json([
                    'success' => true,
                    'message' => 'Credentials are valid',
                    'environment' => $account->environment,
                    'tested_at' => now(),
                    'merchant_name' => $response->getMerchantName(),
                    'gateway_id' => $response->getGatewayId(),
                ]);
            } else {
                $errorMessages = [];
                if ($response !== null && $response->getMessages()->getMessage()) {
                    foreach ($response->getMessages()->getMessage() as $message) {
                        $errorMessages[] = [
                            'code' => $message->getCode(),
                            'text' => $message->getText()
                        ];
                    }
                }

                Log::warning('Authorize.Net authentication test failed for location', [
                    'location_id' => $locationId,
                    'account_id' => $account->id,
                    'environment' => $account->environment,
                    'errors' => $errorMessages
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Authentication failed - credentials may be incorrect',
                    'environment' => $account->environment,
                    'tested_at' => now(),
                    'errors' => $errorMessages
                ]);
            }

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Cannot test - decryption failed for location', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credentials are encrypted with a different key. Please reconnect the account.',
                'credentials_valid' => false
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to test Authorize.Net connection for location', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to test connection',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
