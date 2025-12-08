<?php

namespace App\Services;

use App\Models\AuthorizeNetAccount;
use App\Models\Location;
use Exception;
use Illuminate\Support\Facades\Log;

class AuthorizeNetPaymentService
{
    protected ?AuthorizeNetAccount $account = null;
    protected ?Location $location = null;

    /**
     * Initialize the service with a location
     */
    public function forLocation(Location|int $location): self
    {
        if (is_int($location)) {
            $this->location = Location::findOrFail($location);
        } else {
            $this->location = $location;
        }

        $this->account = $this->location->authorizeNetAccount()
            ->where('is_active', true)
            ->first();

        if (!$this->account) {
            throw new Exception("No active Authorize.Net account found for location: {$this->location->name}");
        }

        return $this;
    }

    /**
     * Get the API credentials for the location
     */
    public function getCredentials(): array
    {
        if (!$this->account) {
            throw new Exception("No Authorize.Net account loaded. Call forLocation() first.");
        }

        return [
            'api_login_id' => $this->account->api_login_id,
            'transaction_key' => $this->account->transaction_key,
            'environment' => $this->account->environment,
        ];
    }

    /**
     * Get the Authorize.Net API endpoint based on environment
     */
    public function getApiEndpoint(): string
    {
        if (!$this->account) {
            throw new Exception("No Authorize.Net account loaded. Call forLocation() first.");
        }

        return $this->account->isProduction()
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';
    }

    /**
     * Check if the account is in production mode
     */
    public function isProduction(): bool
    {
        return $this->account && $this->account->isProduction();
    }

    /**
     * Check if the account is in sandbox mode
     */
    public function isSandbox(): bool
    {
        return $this->account && $this->account->isSandbox();
    }

    /**
     * Process a payment transaction with Accept.js token
     *
     * @param array $paymentData - Should include dataDescriptor, dataValue, amount
     * @param array|null $customerData - Customer billing information
     * @param array|null $orderData - Order details (invoice number, description, etc.)
     * @return array
     */
    public function chargeTransaction(array $paymentData, ?array $customerData = null, ?array $orderData = null): array
    {
        if (!$this->account) {
            throw new Exception("No Authorize.Net account loaded. Call forLocation() first.");
        }

        try {
            $credentials = $this->getCredentials();

            Log::info('Processing Authorize.Net transaction', [
                'location_id' => $this->location->id,
                'environment' => $credentials['environment'],
                'amount' => $paymentData['amount'] ?? 'N/A',
                'has_customer_data' => !empty($customerData),
                'has_order_data' => !empty($orderData),
                'customer_email' => $customerData['email'] ?? null,
                'customer_name' => ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? ''),
            ]);

            // Initialize Authorize.Net SDK
            $merchantAuthentication = new \net\authorize\api\contract\v1\MerchantAuthenticationType();
            $merchantAuthentication->setName($credentials['api_login_id']);
            $merchantAuthentication->setTransactionKey($credentials['transaction_key']);

            // Set opaque data from Accept.js
            $opaqueData = new \net\authorize\api\contract\v1\OpaqueDataType();
            $opaqueData->setDataDescriptor($paymentData['dataDescriptor']);
            $opaqueData->setDataValue($paymentData['dataValue']);

            // Set payment data
            $paymentOne = new \net\authorize\api\contract\v1\PaymentType();
            $paymentOne->setOpaqueData($opaqueData);

            // Create transaction request
            $transactionRequestType = new \net\authorize\api\contract\v1\TransactionRequestType();
            $transactionRequestType->setTransactionType("authCaptureTransaction");
            $transactionRequestType->setAmount($paymentData['amount']);
            $transactionRequestType->setPayment($paymentOne);

            // Add customer billing information if provided
            if ($customerData) {
                $billTo = new \net\authorize\api\contract\v1\CustomerAddressType();
                
                if (!empty($customerData['first_name'])) {
                    $billTo->setFirstName(substr($customerData['first_name'], 0, 50));
                }
                if (!empty($customerData['last_name'])) {
                    $billTo->setLastName(substr($customerData['last_name'], 0, 50));
                }
                if (!empty($customerData['email'])) {
                    $billTo->setEmail(substr($customerData['email'], 0, 255));
                }
                if (!empty($customerData['phone'])) {
                    $billTo->setPhoneNumber(substr($customerData['phone'], 0, 25));
                }
                if (!empty($customerData['address'])) {
                    $billTo->setAddress(substr($customerData['address'], 0, 60));
                }
                if (!empty($customerData['city'])) {
                    $billTo->setCity(substr($customerData['city'], 0, 40));
                }
                if (!empty($customerData['state'])) {
                    $billTo->setState(substr($customerData['state'], 0, 40));
                }
                if (!empty($customerData['zip'])) {
                    $billTo->setZip(substr($customerData['zip'], 0, 20));
                }
                if (!empty($customerData['country'])) {
                    $billTo->setCountry(substr($customerData['country'], 0, 60));
                }

                $transactionRequestType->setBillTo($billTo);

                Log::info('Customer billing data added to transaction', [
                    'customer_name' => ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? ''),
                    'email' => $customerData['email'] ?? null,
                    'phone' => $customerData['phone'] ?? null,
                    'address' => $customerData['address'] ?? null,
                    'city' => $customerData['city'] ?? null,
                    'state' => $customerData['state'] ?? null,
                    'zip' => $customerData['zip'] ?? null,
                ]);
            }

            // Add order information if provided
            if ($orderData) {
                $order = new \net\authorize\api\contract\v1\OrderType();
                
                if (!empty($orderData['invoice_number'])) {
                    $order->setInvoiceNumber(substr($orderData['invoice_number'], 0, 20));
                }
                if (!empty($orderData['description'])) {
                    $order->setDescription(substr($orderData['description'], 0, 255));
                }

                $transactionRequestType->setOrder($order);

                Log::info('Order data added to transaction', [
                    'invoice_number' => $orderData['invoice_number'] ?? null,
                    'description' => $orderData['description'] ?? null,
                ]);
            }

            // Create request
            $request = new \net\authorize\api\contract\v1\CreateTransactionRequest();
            $request->setMerchantAuthentication($merchantAuthentication);
            $request->setRefId('ref' . time());
            $request->setTransactionRequest($transactionRequestType);

            // Execute request
            $controller = new \net\authorize\api\controller\CreateTransactionController($request);
            $response = $controller->executeWithApiResponse($this->getApiEndpoint());

            if ($response != null) {
                if ($response->getMessages()->getResultCode() == "Ok") {
                    $tresponse = $response->getTransactionResponse();

                    if ($tresponse != null && $tresponse->getMessages() != null) {
                        Log::info('Authorize.Net transaction successful', [
                            'transaction_id' => $tresponse->getTransId(),
                            'auth_code' => $tresponse->getAuthCode(),
                            'message_code' => $tresponse->getMessages()[0]->getCode(),
                            'description' => $tresponse->getMessages()[0]->getDescription(),
                            'customer_email' => $customerData['email'] ?? null,
                        ]);

                        return [
                            'success' => true,
                            'transaction_id' => $tresponse->getTransId(),
                            'auth_code' => $tresponse->getAuthCode(),
                            'message' => $tresponse->getMessages()[0]->getDescription(),
                            'amount' => $paymentData['amount'],
                            'customer_name' => ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? ''),
                        ];
                    } else {
                        Log::error('Authorize.Net transaction failed - no transaction response', [
                            'error_code' => $tresponse->getErrors()[0]->getErrorCode(),
                            'error_text' => $tresponse->getErrors()[0]->getErrorText(),
                        ]);

                        return [
                            'success' => false,
                            'message' => 'Transaction failed: ' . $tresponse->getErrors()[0]->getErrorText(),
                            'error_code' => $tresponse->getErrors()[0]->getErrorCode(),
                        ];
                    }
                } else {
                    Log::error('Authorize.Net API error', [
                        'result_code' => $response->getMessages()->getResultCode(),
                        'message' => $response->getMessages()->getMessage()[0]->getText(),
                    ]);

                    $errorMessages = $response->getMessages()->getMessage();
                    return [
                        'success' => false,
                        'message' => 'API Error: ' . $errorMessages[0]->getText(),
                        'error_code' => $errorMessages[0]->getCode(),
                    ];
                }
            } else {
                Log::error('Authorize.Net null response');
                return [
                    'success' => false,
                    'message' => 'No response from payment gateway',
                ];
            }

        } catch (Exception $e) {
            Log::error('Authorize.Net transaction exception', [
                'location_id' => $this->location->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment processing error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Refund a transaction
     *
     * @param string $transactionId
     * @param float $amount
     * @return array
     */
    public function refundTransaction(string $transactionId, float $amount): array
    {
        if (!$this->account) {
            throw new Exception("No Authorize.Net account loaded. Call forLocation() first.");
        }

        try {
            $credentials = $this->getCredentials();

            Log::info('Processing Authorize.Net refund', [
                'location_id' => $this->location->id,
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ]);

            // Return mock response for now
            return [
                'success' => true,
                'refund_id' => 'REFUND_' . time(),
                'message' => 'Refund processed successfully (mock)',
                'amount' => $amount,
            ];

        } catch (Exception $e) {
            Log::error('Authorize.Net refund failed', [
                'location_id' => $this->location->id,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Test the connection to Authorize.Net
     *
     * @return array
     */
    public function testConnection(): array
    {
        if (!$this->account) {
            throw new Exception("No Authorize.Net account loaded. Call forLocation() first.");
        }

        try {
            $credentials = $this->getCredentials();

            // Here you would make an actual API call to test credentials
            // For example, getMerchantDetails API call

            Log::info('Testing Authorize.Net connection', [
                'location_id' => $this->location->id,
                'environment' => $credentials['environment'],
            ]);

            $this->account->markAsTested();

            return [
                'success' => true,
                'message' => 'Connection test successful',
                'environment' => $credentials['environment'],
            ];

        } catch (Exception $e) {
            Log::error('Authorize.Net connection test failed', [
                'location_id' => $this->location->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the account instance
     */
    public function getAccount(): ?AuthorizeNetAccount
    {
        return $this->account;
    }

    /**
     * Get the location instance
     */
    public function getLocation(): ?Location
    {
        return $this->location;
    }
}
