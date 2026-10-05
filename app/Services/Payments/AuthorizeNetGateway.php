<?php

namespace App\Services\Payments;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AuthorizeNetGateway
{
    public const NO_ANSWER_MESSAGE = "We couldn't get an answer from the card processor, so we can't tell whether your card was charged. Please call us before trying again so you are not charged twice.";

    public function execute(AnetController\base\ApiOperationBase $controller, string $environment)
    {
        return $controller->executeWithApiResponse($environment);
    }

    public function answered($response): bool
    {
        return $response !== null && $response->getMessages() !== null;
    }

    public function reportNoAnswer(?int $locationId, float $amount, string $what, string $reference, array $context = []): void
    {
        Log::error('CHARGE_OUTCOME_UNKNOWN: Authorize.Net did not answer a card charge', $context + [
            'location_id' => $locationId,
            'amount' => $amount,
            'what' => $what,
            'reference' => $reference,
        ]);

        $this->alertStaff(
            $locationId,
            'Card payment needs checking in Authorize.Net',
            'Authorize.Net did not answer a card payment of $' . number_format($amount, 2) . " for {$what}, so the customer was told it did not go through. Check Authorize.Net for a charge with reference {$reference} and refund it if one went through.",
            $context + ['amount' => $amount, 'reference' => $reference, 'reason' => 'no_gateway_answer']
        );
    }

    public function isApproved(?AnetAPI\TransactionResponseType $transaction, float $requestedAmount): bool
    {
        if ($transaction === null || (string) $transaction->getResponseCode() !== '1') {
            return false;
        }

        $transactionId = (string) $transaction->getTransId();
        if ($transactionId === '' || $transactionId === '0' || !empty($transaction->getSplitTenderId())) {
            return false;
        }

        $approvedAmount = $transaction->getPrePaidCard()?->getApprovedAmount();

        return $approvedAmount === null || $approvedAmount === ''
            || round((float) $approvedAmount, 2) >= round($requestedAmount, 2);
    }

    public function outcome(?AnetAPI\TransactionResponseType $transaction): array
    {
        $message = ($transaction?->getMessages() ?? [])[0] ?? null;
        $error = ($transaction?->getErrors() ?? [])[0] ?? null;

        return [
            'transaction_id' => $transaction?->getTransId(),
            'response_code' => $transaction?->getResponseCode(),
            'gateway_message_code' => $message?->getCode(),
            'gateway_message' => $message?->getDescription(),
            'gateway_error_code' => $error?->getErrorCode(),
            'gateway_error' => $error?->getErrorText(),
            'split_tender_id' => $transaction?->getSplitTenderId(),
            'approved_amount' => $transaction?->getPrePaidCard()?->getApprovedAmount(),
        ];
    }

    public function refuseUnapproved(
        AnetAPI\MerchantAuthenticationType $merchantAuthentication,
        string $environment,
        AnetAPI\TransactionResponseType $transaction,
        ?int $locationId,
        float $amount,
        string $what,
        array $context = []
    ): bool {
        $transactionId = (string) $transaction->getTransId();
        $released = $this->release($merchantAuthentication, $environment, $transactionId, $transaction->getSplitTenderId());

        Log::error('CHARGE_NOT_APPROVED: Authorize.Net did not approve this charge outright, so it was refused', $this->outcome($transaction) + $context + [
            'released_at_gateway' => $released,
            'location_id' => $locationId,
            'amount' => $amount,
        ]);

        if (! $released) {
            $this->alertStaff(
                $locationId,
                'Card payment needs action in Authorize.Net',
                "Authorize.Net held transaction {$transactionId} for $" . number_format($amount, 2) . " ({$what}) instead of approving it, and it could not be cancelled automatically. The customer was told it did not go through, so decline it in Authorize.Net.",
                $context + [
                    'transaction_id' => $transactionId,
                    'response_code' => (string) $transaction->getResponseCode(),
                    'amount' => $amount,
                    'reason' => 'not_approved',
                ]
            );
        }

        return $released;
    }

    public function release(AnetAPI\MerchantAuthenticationType $merchantAuthentication, string $environment, ?string $transactionId, ?string $splitTenderId = null): bool
    {
        if ($transactionId === null || $transactionId === '' || $transactionId === '0') {
            return true;
        }

        if (empty($splitTenderId)) {
            try {
                $held = new AnetAPI\HeldTransactionRequestType();
                $held->setAction('decline');
                $held->setRefTransId($transactionId);

                $declineRequest = new AnetAPI\UpdateHeldTransactionRequest();
                $declineRequest->setMerchantAuthentication($merchantAuthentication);
                $declineRequest->setHeldTransactionRequest($held);

                $declined = $this->execute(new AnetController\UpdateHeldTransactionController($declineRequest), $environment);

                if ($declined != null && $declined->getMessages()?->getResultCode() === 'Ok') {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('Declining a held Authorize.Net transaction failed', ['transaction_id' => $transactionId, 'error' => $e->getMessage()]);
            }
        }

        return $this->void($merchantAuthentication, $environment, $transactionId, $splitTenderId);
    }

    public function void(AnetAPI\MerchantAuthenticationType $merchantAuthentication, string $environment, string $transactionId, ?string $splitTenderId = null): bool
    {
        try {
            $void = new AnetAPI\TransactionRequestType();
            $void->setTransactionType('voidTransaction');
            if (!empty($splitTenderId)) {
                $void->setSplitTenderId($splitTenderId);
            } else {
                $void->setRefTransId($transactionId);
            }

            $voidRequest = new AnetAPI\CreateTransactionRequest();
            $voidRequest->setMerchantAuthentication($merchantAuthentication);
            $voidRequest->setTransactionRequest($void);

            $voided = $this->execute(new AnetController\CreateTransactionController($voidRequest), $environment);

            return $voided != null && $voided->getMessages()?->getResultCode() === 'Ok';
        } catch (\Throwable $e) {
            Log::warning('Voiding an Authorize.Net transaction failed', ['transaction_id' => $transactionId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function alertStaff(?int $locationId, string $title, string $message, array $metadata = []): void
    {
        if ($locationId === null) {
            Log::critical('Card payment needs staff attention: ' . $title, ['message' => $message, 'metadata' => $metadata]);

            return;
        }

        try {
            Notification::create([
                'location_id' => $locationId,
                'type' => 'payment',
                'priority' => 'high',
                'user_id' => null,
                'title' => $title,
                'message' => $message,
                'status' => 'unread',
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not alert staff about a card payment that needs attention', [
                'title' => $title,
                'metadata' => $metadata,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
