<?php

namespace App\Console\Commands;

use App\Models\AuthorizeNetAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReEncryptAuthorizeNetCredentials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'authorizenet:re-encrypt
                            {--old-key= : The old APP_KEY to decrypt with}
                            {--test : Test mode - show what would be re-encrypted without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-encrypt Authorize.Net credentials when APP_KEY changes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $oldKey = $this->option('old-key');
        $testMode = $this->option('test');

        if (!$oldKey) {
            $this->error('You must provide the old APP_KEY using --old-key option');
            $this->info('Usage: php artisan authorizenet:re-encrypt --old-key="base64:YOUR_OLD_KEY"');
            return 1;
        }

        $this->info('Starting Authorize.Net credentials re-encryption...');
        if ($testMode) {
            $this->warn('Running in TEST mode - no changes will be made');
        }

        // Get all accounts
        $accounts = DB::table('authorize_net_accounts')->get();

        if ($accounts->isEmpty()) {
            $this->warn('No Authorize.Net accounts found.');
            return 0;
        }

        $this->info("Found {$accounts->count()} account(s) to process");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;

        // Set old key temporarily
        config(['app.key' => $oldKey]);
        $oldCrypter = app('encrypter');

        // Get current key
        $currentKey = config('app.key');
        config(['app.key' => $currentKey]);
        $newCrypter = app('encrypter');

        foreach ($accounts as $account) {
            try {
                $this->info("Processing Account ID: {$account->id} (Location: {$account->location_id})");

                // Decrypt with old key
                $apiLoginId = $oldCrypter->decryptString($account->api_login_id);
                $transactionKey = $oldCrypter->decryptString($account->transaction_key);

                $this->line("  ✓ Successfully decrypted credentials");

                if (!$testMode) {
                    // Re-encrypt with new key
                    $newApiLoginId = $newCrypter->encryptString($apiLoginId);
                    $newTransactionKey = $newCrypter->encryptString($transactionKey);

                    // Update in database
                    DB::table('authorize_net_accounts')
                        ->where('id', $account->id)
                        ->update([
                            'api_login_id' => $newApiLoginId,
                            'transaction_key' => $newTransactionKey,
                            'updated_at' => now(),
                        ]);

                    $this->line("  ✓ Re-encrypted and saved successfully");
                } else {
                    $this->line("  → Would re-encrypt credentials (TEST MODE)");
                }

                $successCount++;
                $this->newLine();

            } catch (\Exception $e) {
                $this->error("  ✗ Failed to process Account ID: {$account->id}");
                $this->error("    Error: {$e->getMessage()}");
                $failCount++;
                $this->newLine();
            }
        }

        // Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Total accounts: {$accounts->count()}");
        $this->info("Successfully processed: {$successCount}");
        if ($failCount > 0) {
            $this->error("Failed: {$failCount}");
        }

        if ($testMode) {
            $this->warn('TEST MODE - Run without --test to apply changes');
        }

        return 0;
    }
}
