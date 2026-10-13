<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('memberships', 'charge_outcome_unknown_at')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->timestamp('charge_outcome_unknown_at')->nullable();
            });
        }

        if (! Schema::hasColumn('gift_cards', 'checkout_key')) {
            Schema::table('gift_cards', function (Blueprint $table) {
                $table->string('checkout_key', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gift_cards', 'checkout_key')) {
            Schema::table('gift_cards', function (Blueprint $table) {
                $table->dropUnique(['checkout_key']);
                $table->dropColumn('checkout_key');
            });
        }

        if (Schema::hasColumn('memberships', 'charge_outcome_unknown_at')) {
            Schema::table('memberships', function (Blueprint $table) {
                $table->dropColumn('charge_outcome_unknown_at');
            });
        }
    }
};
