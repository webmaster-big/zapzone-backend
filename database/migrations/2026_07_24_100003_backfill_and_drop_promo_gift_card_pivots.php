<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('package_promos')) {
            DB::table('package_promos')
                ->select('promo_id', 'package_id')
                ->get()
                ->groupBy('promo_id')
                ->each(function ($rows, $promoId) {
                    $ids = $rows->pluck('package_id')->map(fn ($v) => (int) $v)->values()->all();
                    DB::table('promos')->where('id', $promoId)->update(['package_ids' => json_encode($ids)]);
                });
        }

        if (Schema::hasTable('package_gift_cards')) {
            DB::table('package_gift_cards')
                ->select('gift_card_id', 'package_id')
                ->get()
                ->groupBy('gift_card_id')
                ->each(function ($rows, $giftCardId) {
                    $ids = $rows->pluck('package_id')->map(fn ($v) => (int) $v)->values()->all();
                    DB::table('gift_cards')->where('id', $giftCardId)->update(['package_ids' => json_encode($ids)]);
                });
        }

        foreach (DB::table('gift_cards')->whereNotNull('location_id')->get(['id', 'location_id']) as $giftCard) {
            DB::table('gift_cards')
                ->where('id', $giftCard->id)
                ->update(['location_ids' => json_encode([(int) $giftCard->location_id])]);
        }

        Schema::dropIfExists('package_promos');
        Schema::dropIfExists('package_gift_cards');
    }

    public function down(): void
    {
        if (!Schema::hasTable('package_promos')) {
            Schema::create('package_promos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_id')->constrained()->onDelete('cascade');
                $table->foreignId('promo_id')->constrained()->onDelete('cascade');
                $table->timestamps();

                $table->index('package_id');
                $table->index('promo_id');

                $table->unique(['package_id', 'promo_id']);
            });
        }

        if (!Schema::hasTable('package_gift_cards')) {
            Schema::create('package_gift_cards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_id')->constrained()->onDelete('cascade');
                $table->foreignId('gift_card_id')->constrained()->onDelete('cascade');
                $table->timestamps();

                $table->index('package_id');
                $table->index('gift_card_id');

                $table->unique(['package_id', 'gift_card_id']);
            });
        }

        foreach (DB::table('promos')->whereNotNull('package_ids')->get(['id', 'package_ids']) as $promo) {
            foreach (json_decode($promo->package_ids, true) ?: [] as $packageId) {
                DB::table('package_promos')->insert([
                    'package_id' => (int) $packageId,
                    'promo_id' => $promo->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('gift_cards')->whereNotNull('package_ids')->get(['id', 'package_ids']) as $giftCard) {
            foreach (json_decode($giftCard->package_ids, true) ?: [] as $packageId) {
                DB::table('package_gift_cards')->insert([
                    'package_id' => (int) $packageId,
                    'gift_card_id' => $giftCard->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
