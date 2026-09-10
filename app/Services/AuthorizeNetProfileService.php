<?php

namespace App\Services;

use App\Models\AuthorizeNetAccount;
use Illuminate\Support\Facades\Log;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AuthorizeNetProfileService
{
    public function saveCard(
        AuthorizeNetAccount $account,
        ?string $existingCustomerProfileId,
        array $opaqueData,
        array $billTo,
        string $merchantCustomerId,
        ?string $email = null
    ): array {
        try {
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;
            $validationMode = $account->isProduction() ? 'liveMode' : 'testMode';

            $paymentProfile = new AnetAPI\CustomerPaymentProfileType();
            $paymentProfile->setCustomerType('individual');
            $paymentProfile->setBillTo($this->buildAddress($billTo));
            $paymentProfile->setPayment($this->buildOpaquePayment($opaqueData));

            if ($existingCustomerProfileId) {
                $request = new AnetAPI\CreateCustomerPaymentProfileRequest();
                $request->setMerchantAuthentication($this->auth($account));
                $request->setCustomerProfileId($existingCustomerProfileId);
                $request->setPaymentProfile($paymentProfile);
                $request->setValidationMode($validationMode);

                $controller = new AnetController\CreateCustomerPaymentProfileController($request);
                $response = $controller->executeWithApiResponse($environment);

                if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                    return [
                        'success' => true,
                        'customer_profile_id' => $existingCustomerProfileId,
                        'payment_profile_id' => (string) $response->getCustomerPaymentProfileId(),
                        'error' => null,
                    ];
                }

                return $this->failure($response, $account, 'create_payment_profile');
            }

            $customerProfile = new AnetAPI\CustomerProfileType();
            $customerProfile->setMerchantCustomerId(substr($merchantCustomerId, 0, 20));
            if ($email) {
                $customerProfile->setEmail(substr($email, 0, 255));
            }
            $customerProfile->setPaymentProfiles([$paymentProfile]);

            $request = new AnetAPI\CreateCustomerProfileRequest();
            $request->setMerchantAuthentication($this->auth($account));
            $request->setProfile($customerProfile);
            $request->setValidationMode($validationMode);

            $controller = new AnetController\CreateCustomerProfileController($request);
            $response = $controller->executeWithApiResponse($environment);

            if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                $ids = $response->getCustomerPaymentProfileIdList();

                return [
                    'success' => true,
                    'customer_profile_id' => (string) $response->getCustomerProfileId(),
                    'payment_profile_id' => isset($ids[0]) ? (string) $ids[0] : null,
                    'error' => null,
                ];
            }

            $duplicateProfileId = $this->duplicateProfileId($response);

            if ($duplicateProfileId) {
                Log::info('Authorize.Net customer profile already existed; adding card to it', [
                    'location_id' => $account->location_id,
                    'customer_profile_id' => $duplicateProfileId,
                ]);

                return $this->saveCard($account, $duplicateProfileId, $opaqueData, $billTo, $merchantCustomerId, $email);
            }

            return $this->failure($response, $account, 'create_customer_profile');
        } catch (\Throwable $e) {
            Log::error('Authorize.Net profile save exception', [
                'location_id' => $account->location_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'customer_profile_id' => null,
                'payment_profile_id' => null,
                'error' => 'Could not save the card. Please try again.',
            ];
        }
    }

    public function chargeProfile(
        AuthorizeNetAccount $account,
        string $customerProfileId,
        string $paymentProfileId,
        float $amount,
        string $refId,
        string $description
    ): array {
        try {
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
            $profileToCharge->setCustomerProfileId($customerProfileId);

            $paymentProfile = new AnetAPI\PaymentProfileType();
            $paymentProfile->setPaymentProfileId($paymentProfileId);
            $profileToCharge->setPaymentProfile($paymentProfile);

            $order = new AnetAPI\OrderType();
            $order->setDescription(substr($description, 0, 255));

            $transactionRequest = new AnetAPI\TransactionRequestType();
            $transactionRequest->setTransactionType('authCaptureTransaction');
            $transactionRequest->setAmount($amount);
            $transactionRequest->setProfile($profileToCharge);
            $transactionRequest->setOrder($order);

            $request = new AnetAPI\CreateTransactionRequest();
            $request->setMerchantAuthentication($this->auth($account));
            $request->setRefId(substr($refId, 0, 20));
            $request->setTransactionRequest($transactionRequest);

            $controller = new AnetController\CreateTransactionController($request);
            $response = $controller->executeWithApiResponse($environment);

            if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getMessages()) {
                    return [
                        'success' => true,
                        'transaction_id' => $tresponse->getTransId(),
                        'error' => null,
                    ];
                }
            }

            $error = 'Payment declined';
            if ($response) {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getErrors()) {
                    $error = $tresponse->getErrors()[0]->getErrorText();
                } elseif ($response->getMessages()) {
                    $error = $response->getMessages()->getMessage()[0]->getText();
                }
            }

            Log::warning('Authorize.Net profile charge failed', [
                'ref_id' => $refId,
                'location_id' => $account->location_id,
                'error' => $error,
            ]);

            return ['success' => false, 'transaction_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('Authorize.Net profile charge exception', [
                'ref_id' => $refId,
                'location_id' => $account->location_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'transaction_id' => null, 'error' => 'Payment processing error.'];
        }
    }

    private function duplicateProfileId(?object $response): ?string
    {
        if (!$response || !$response->getMessages() || !$response->getMessages()->getMessage()) {
            return null;
        }

        foreach ($response->getMessages()->getMessage() as $message) {
            if ($message->getCode() !== 'E00039') {
                continue;
            }

            if (preg_match('/\d+/', (string) $message->getText(), $matches)) {
                return $matches[0];
            }
        }

        return null;
    }

    private function auth(AuthorizeNetAccount $account): AnetAPI\MerchantAuthenticationType
    {
        $auth = new AnetAPI\MerchantAuthenticationType();
        $auth->setName(trim((string) $account->api_login_id));
        $auth->setTransactionKey(trim((string) $account->transaction_key));

        return $auth;
    }

    private function buildOpaquePayment(array $opaqueData): AnetAPI\PaymentType
    {
        $opaque = new AnetAPI\OpaqueDataType();
        $opaque->setDataDescriptor($opaqueData['dataDescriptor'] ?? '');
        $opaque->setDataValue($opaqueData['dataValue'] ?? '');

        $payment = new AnetAPI\PaymentType();
        $payment->setOpaqueData($opaque);

        return $payment;
    }

    private function buildAddress(array $billTo): AnetAPI\CustomerAddressType
    {
        $address = new AnetAPI\CustomerAddressType();
        $address->setFirstName(substr((string) ($billTo['first_name'] ?? ''), 0, 50));
        $address->setLastName(substr((string) ($billTo['last_name'] ?? ''), 0, 50));
        if (!empty($billTo['address'])) {
            $address->setAddress(substr((string) $billTo['address'], 0, 60));
        }
        if (!empty($billTo['city'])) {
            $address->setCity(substr((string) $billTo['city'], 0, 40));
        }
        if (!empty($billTo['state'])) {
            $address->setState(substr((string) $billTo['state'], 0, 40));
        }
        if (!empty($billTo['zip'])) {
            $address->setZip(substr((string) $billTo['zip'], 0, 20));
        }
        if (!empty($billTo['country'])) {
            $address->setCountry(substr((string) $billTo['country'], 0, 60));
        }

        return $address;
    }

    private function failure(?object $response, AuthorizeNetAccount $account, string $stage): array
    {
        $error = 'Could not save the card.';
        if ($response && $response->getMessages() && $response->getMessages()->getMessage()) {
            $error = $response->getMessages()->getMessage()[0]->getText();
        }

        Log::warning('Authorize.Net profile save failed', [
            'stage' => $stage,
            'location_id' => $account->location_id,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'customer_profile_id' => null,
            'payment_profile_id' => null,
            'error' => $error,
        ];
    }
}
