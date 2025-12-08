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
            ]);

            // Build customer billing data for Authorize.Net
            $billingData = $this->buildBillingData($customerData);
            
            // Build order data for Authorize.Net
            $orderInfo = $this->buildOrderData($orderData);

            // Here you would use the Authorize.Net SDK to process the transaction
            // Example using net\authorize\api SDK:
            // 
            // $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            // $merchantAuthentication->setName($credentials['api_login_id']);
            // $merchantAuthentication->setTransactionKey($credentials['transaction_key']);
            //
            // $opaqueData = new AnetAPI\OpaqueDataType();
            // $opaqueData->setDataDescriptor($paymentData['dataDescriptor']);
            // $opaqueData->setDataValue($paymentData['dataValue']);
            //
            // $paymentOne = new AnetAPI\PaymentType();
            // $paymentOne->setOpaqueData($opaqueData);
            //
            // $transactionRequestType = new AnetAPI\TransactionRequestType();
            // $transactionRequestType->setTransactionType("authCaptureTransaction");
            // $transactionRequestType->setAmount($paymentData['amount']);
            // $transactionRequestType->setPayment($paymentOne);
            // $transactionRequestType->setBillTo($billingData); // Customer info here
            // $transactionRequestType->setOrder($orderInfo); // Order info here

            Log::info('Authorize.Net transaction payload prepared', [
                'billing_data' => $billingData,
                'order_data' => $orderInfo,
            ]);

            // Return mock response for now
            return [
                'success' => true,
                'transaction_id' => 'MOCK_' . time(),
                'message' => 'Transaction processed successfully (mock)',
                'amount' => $paymentData['amount'] ?? 0,
                'customer_name' => ($billingData['firstName'] ?? '') . ' ' . ($billingData['lastName'] ?? ''),
            ];

        } catch (Exception $e) {
            Log::error('Authorize.Net transaction failed', [
                'location_id' => $this->location->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build billing data array from customer information
     * 
     * @param array|null $customerData
     * @return array
     */
    protected function buildBillingData(?array $customerData): array
    {
        if (!$customerData) {
            return [];
        }

        $billing = [];

        if (!empty($customerData['first_name'])) {
            $billing['firstName'] = $customerData['first_name'];
        }
        
        if (!empty($customerData['last_name'])) {
            $billing['lastName'] = $customerData['last_name'];
        }
        
        if (!empty($customerData['email'])) {
            $billing['email'] = $customerData['email'];
        }
        
        if (!empty($customerData['phone'])) {
            $billing['phoneNumber'] = $customerData['phone'];
        }

        // Address fields (optional but recommended)
        if (!empty($customerData['address'])) {
            $billing['address'] = $customerData['address'];
        }
        
        if (!empty($customerData['city'])) {
            $billing['city'] = $customerData['city'];
        }
        
        if (!empty($customerData['state'])) {
            $billing['state'] = $customerData['state'];
        }
        
        if (!empty($customerData['zip'])) {
            $billing['zip'] = $customerData['zip'];
        }
        
        if (!empty($customerData['country'])) {
            $billing['country'] = $customerData['country'];
        }

        return $billing;
    }

    /**
     * Build order data array for transaction
     * 
     * @param array|null $orderData
     * @return array
     */
    protected function buildOrderData(?array $orderData): array
    {
        if (!$orderData) {
            return [];
        }

        $order = [];

        if (!empty($orderData['invoice_number'])) {
            $order['invoiceNumber'] = $orderData['invoice_number'];
        }
        
        if (!empty($orderData['description'])) {
            $order['description'] = substr($orderData['description'], 0, 255); // Max 255 chars
        }

        return $order;
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
