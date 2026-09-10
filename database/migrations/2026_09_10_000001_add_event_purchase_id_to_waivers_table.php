<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('waivers', 'event_purchase_id')) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->foreignId('event_purchase_id')->nullable()->constrained()->nullOnDelete();
                $table->index('event_purchase_id');
            });
        }

        $this->backfillUnambiguous();
    }

    public function down(): void
    {
        if (Schema::hasColumn('waivers', 'event_purchase_id')) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('event_purchase_id');
            });
        }
    }

    private function backfillUnambiguous(): void
    {
        DB::table('waivers')
            ->select('id', 'event_id', 'customer_id', 'selected_date')
            ->whereNull('event_purchase_id')
            ->whereNotNull('event_id')
            ->orderBy('id')
            ->chunkById(500, function ($waivers) {
                foreach ($waivers as $waiver) {
                    if (!$waiver->selected_date) {
                        continue;
                    }

                    $candidates = DB::table('event_purchases')
                        ->where('event_id', $waiver->event_id)
                        ->whereDate('purchase_date', $waiver->selected_date)
                        ->when(
                            $waiver->customer_id !== null,
                            fn ($q) => $q->where('customer_id', $waiver->customer_id),
                            fn ($q) => $q->whereNull('customer_id'),
                        )
                        ->pluck('id');

                    if ($candidates->count() !== 1) {
                        continue;
                    }

                    DB::table('waivers')
                        ->where('id', $waiver->id)
                        ->update(['event_purchase_id' => $candidates->first()]);
                }
            });
    }
};
