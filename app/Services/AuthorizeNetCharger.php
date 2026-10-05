<?php

namespace App\Services;

use App\Support\CardBrand;
use App\Models\AuthorizeNetAccount;
use App\Services\Payments\AuthorizeNetGateway;
use Illuminate\Support\Facades\Log;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AuthorizeNetCharger
{
    public function charge(
        AuthorizeNetAccount $account,
        float $amount,
        array $opaqueData,
        array $billTo,
        string $invoiceNumber,
        string $refId,
        string $description
    ): array {
        $sent = false;

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName(trim((string) $account->api_login_id));
            $merchantAuthentication->setTransactionKey(trim((string) $account->transaction_key));
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            $opaque = new AnetAPI\OpaqueDataType();
            $opaque->setDataDescriptor($opaqueData['dataDescriptor'] ?? '');
            $opaque->setDataValue($opaqueData['dataValue'] ?? '');

            $paymentType = new AnetAPI\PaymentType();
            $paymentType->setOpaqueData($opaque);

            $address = new AnetAPI\CustomerAddressType();
            $address->setFirstName(substr((string) ($billTo['first_name'] ?? ''), 0, 50));
            $address->setLastName(substr((string) ($billTo['last_name'] ?? ''), 0, 50));
            if (!empty($billTo['email'])) {
                $address->setEmail(substr((string) $billTo['email'], 0, 255));
            }
            if (!empty($billTo['phone'])) {
                $address->setPhoneNumber(substr((string) $billTo['phone'], 0, 25));
            }

            $order = new AnetAPI\OrderType();
            $order->setInvoiceNumber(substr($invoiceNumber, 0, 20));
            $order->setDescription(substr($description, 0, 255));

            $transactionRequest = new AnetAPI\TransactionRequestType();
            $transactionRequest->setTransactionType('authCaptureTransaction');
            $transactionRequest->setAmount($amount);
            $transactionRequest->setPayment($paymentType);
            $transactionRequest->setBillTo($address);
            $transactionRequest->setOrder($order);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setRefId(substr($refId, 0, 20));
            $apiRequest->setTransactionRequest($transactionRequest);

            $gateway = app(AuthorizeNetGateway::class);
            $controller = new AnetController\CreateTransactionController($apiRequest);
            $sent = true;
            $response = $gateway->execute($controller, $environment);

            if (! $gateway->answered($response)) {
                $gateway->reportNoAnswer($account->location_id, $amount, $description, substr($invoiceNumber, 0, 20), ['ref_id' => $refId]);

                return ['success' => false, 'transaction_id' => null, 'card_last_four' => null, 'card_type' => null, 'error' => AuthorizeNetGateway::NO_ANSWER_MESSAGE, 'environment' => $account->environment];
            }

            if ($response->getMessages()->getResultCode() === 'Ok') {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getMessages() && ! $gateway->isApproved($tresponse, $amount)) {
                    $gateway->refuseUnapproved($merchantAuthentication, $environment, $tresponse, $account->location_id, $amount, $description, ['ref_id' => $refId]);

                    return ['success' => false, 'transaction_id' => null, 'card_last_four' => null, 'card_type' => null, 'error' => 'Your card payment was not approved. Please try a different card.', 'environment' => $account->environment];
                }
                if ($tresponse && $tresponse->getMessages()) {
                    return [
                        'success' => true,
                        'transaction_id' => $tresponse->getTransId(),
                        'card_last_four' => CardBrand::lastFour($tresponse->getAccountNumber()),
                        'card_type' => CardBrand::normalize($tresponse->getAccountType()),
                        'error' => null,
                        'environment' => $account->environment,
                    ];
                }
            }

            $errorMessage = 'Payment declined';
            if ($response) {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getErrors()) {
                    $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                } elseif ($response->getMessages()) {
                    $errorMessage = $response->getMessages()->getMessage()[0]->getText();
                }
            }

            Log::warning('Authorize.Net charge failed', [
                'ref_id' => $refId,
                'location_id' => $account->location_id,
                'environment' => $account->environment,
                'error' => $errorMessage,
            ]);

            return ['success' => false, 'transaction_id' => null, 'card_last_four' => null, 'card_type' => null, 'error' => $errorMessage, 'environment' => $account->environment];
        } catch (\Throwable $e) {
            Log::error('Authorize.Net charge exception', [
                'ref_id' => $refId,
                'location_id' => $account->location_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            if ($sent) {
                rescue(fn () => app(AuthorizeNetGateway::class)->reportNoAnswer($account->location_id, $amount, $description, substr($invoiceNumber, 0, 20), ['ref_id' => $refId]), null, false);

                return ['success' => false, 'transaction_id' => null, 'card_last_four' => null, 'card_type' => null, 'error' => AuthorizeNetGateway::NO_ANSWER_MESSAGE, 'environment' => $account->environment];
            }

            return ['success' => false, 'transaction_id' => null, 'card_last_four' => null, 'card_type' => null, 'error' => 'Payment processing error.', 'environment' => $account->environment];
        }
    }
}
