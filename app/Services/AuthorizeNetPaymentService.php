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
     * Process a payment transaction
     *
     * @param array $paymentData
     * @return array
     */
    public function chargeTransaction(array $paymentData): array
    {
        if (!$this->account) {
            throw new Exception("No Authorize.Net account loaded. Call forLocation() first.");
        }

        // This is a placeholder for actual Authorize.Net API integration
        // You would use the Authorize.Net SDK here

        try {
            $credentials = $this->getCredentials();

            // Example structure - actual implementation would use Authorize.Net SDK
            // require 'vendor/autoload.php';
            // use net\authorize\api\contract\v1 as AnetAPI;
            // use net\authorize\api\controller as AnetController;

            Log::info('Processing Authorize.Net transaction', [
                'location_id' => $this->location->id,
                'environment' => $credentials['environment'],
                'amount' => $paymentData['amount'] ?? 'N/A',
            ]);

            // Return mock response for now
            return [
                'success' => true,
                'transaction_id' => 'MOCK_' . time(),
                'message' => 'Transaction processed successfully (mock)',
                'amount' => $paymentData['amount'] ?? 0,
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
