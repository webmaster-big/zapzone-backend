<?php

namespace App\Console\Commands;

use App\Models\AuthorizeNetAccount;
use App\Models\Payment;
use App\Support\CardBrand;
use Illuminate\Console\Command;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class BackfillPaymentCardType extends Command
{
    protected $signature = 'payments:backfill-card-type
        {--limit=200 : How many payments to look up in this run}
        {--location= : Only backfill payments for this location id}
        {--sleep=250 : Milliseconds to wait between gateway calls}
        {--dry-run : Report what would be fetched without writing}';

    protected $description = 'Fill in the card brand on historical card payments from Authorize.Net';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));

        $query = Payment::query()
            ->whereNull('card_type')
            ->whereIn('method', ['card', 'authorize.net'])
            ->whereNotNull('transaction_id')
            ->whereRaw("transaction_id REGEXP '^[0-9]+$'")
            ->whereNotNull('location_id')
            ->orderByDesc('id');

        if ($this->option('location')) {
            $query->where('location_id', (int) $this->option('location'));
        }

        $payments = $query->limit($limit)->get();

        if ($payments->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Looking up {$payments->count()} payment(s)".($dryRun ? ' (dry run)' : '').'.');

        $accounts = [];
        $updated = 0;
        $missed = 0;

        foreach ($payments as $payment) {
            $locationId = (int) $payment->location_id;

            if (! array_key_exists($locationId, $accounts)) {
                $accounts[$locationId] = AuthorizeNetAccount::where('location_id', $locationId)
                    ->where('is_active', true)
                    ->first();
            }

            $account = $accounts[$locationId];

            if (! $account) {
                $this->warn("Payment {$payment->id}: no active gateway account for location {$locationId}.");
                $missed++;

                continue;
            }

            $details = $this->fetch($account, (string) $payment->transaction_id);

            if ($details === null) {
                $missed++;
            } else {
                [$brand, $lastFour] = $details;

                if ($brand === null && $lastFour === null) {
                    $missed++;
                } else {
                    $this->line("Payment {$payment->id}: ".(CardBrand::label($brand, $lastFour ?? $payment->card_last_four) ?? $brand ?? 'unknown'));

                    if (! $dryRun) {
                        $changes = [];

                        if ($brand !== null) {
                            $changes['card_type'] = $brand;
                        }

                        if ($lastFour !== null && ! $payment->card_last_four) {
                            $changes['card_last_four'] = $lastFour;
                        }

                        if ($changes !== []) {
                            $payment->forceFill($changes)->save();
                        }
                    }

                    $updated++;
                }
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Done. {$updated} resolved, {$missed} could not be resolved.");

        return self::SUCCESS;
    }

    private function fetch(AuthorizeNetAccount $account, string $transactionId): ?array
    {
        try {
            $auth = new AnetAPI\MerchantAuthenticationType;
            $auth->setName(trim($account->api_login_id));
            $auth->setTransactionKey(trim($account->transaction_key));

            $request = new AnetAPI\GetTransactionDetailsRequest;
            $request->setMerchantAuthentication($auth);
            $request->setTransId($transactionId);

            $controller = new AnetController\GetTransactionDetailsController($request);
            $response = $controller->executeWithApiResponse(
                $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
            );

            if (! $response || $response->getMessages()->getResultCode() !== 'Ok') {
                return null;
            }

            $transaction = $response->getTransaction();

            if (! $transaction || ! $transaction->getPayment() || ! $transaction->getPayment()->getCreditCard()) {
                return null;
            }

            $card = $transaction->getPayment()->getCreditCard();

            return [
                CardBrand::normalize($card->getCardType()),
                CardBrand::lastFour($card->getCardNumber()),
            ];
        } catch (\Throwable $e) {
            $this->warn("Transaction {$transactionId}: {$e->getMessage()}");

            return null;
        }
    }
}
