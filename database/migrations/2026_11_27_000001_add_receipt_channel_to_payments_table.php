<?php

use App\Support\PaymentChannelBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $missing = array_filter([
            'channel' => ! Schema::hasColumn('payments', 'channel'),
            'reverses_payment_id' => ! Schema::hasColumn('payments', 'reverses_payment_id'),
            'external_reference' => ! Schema::hasColumn('payments', 'external_reference'),
            'recorded_by' => ! Schema::hasColumn('payments', 'recorded_by'),
        ]);

        if ($missing !== []) {
            Schema::table('payments', function (Blueprint $table) use ($missing) {
                if (isset($missing['channel'])) {
                    $table->string('channel', 20)->nullable()->after('method');
                    $table->index('channel');
                }

                if (isset($missing['reverses_payment_id'])) {
                    $table->unsignedBigInteger('reverses_payment_id')->nullable()->after('payment_id');
                    $table->index('reverses_payment_id');
                }

                if (isset($missing['external_reference'])) {
                    $table->string('external_reference', 100)->nullable()->after('transaction_id');
                    $table->index(['location_id', 'external_reference']);
                }

                if (isset($missing['recorded_by'])) {
                    $table->unsignedBigInteger('recorded_by')->nullable()->after('location_id');
                }
            });
        }

        Log::info('Payment ledger channels backfilled', (new PaymentChannelBackfill())->run());
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'channel')) {
                $table->dropIndex(['channel']);
                $table->dropColumn('channel');
            }

            if (Schema::hasColumn('payments', 'reverses_payment_id')) {
                $table->dropIndex(['reverses_payment_id']);
                $table->dropColumn('reverses_payment_id');
            }

            if (Schema::hasColumn('payments', 'external_reference')) {
                $table->dropIndex(['location_id', 'external_reference']);
                $table->dropColumn('external_reference');
            }

            if (Schema::hasColumn('payments', 'recorded_by')) {
                $table->dropColumn('recorded_by');
            }
        });
    }
};
