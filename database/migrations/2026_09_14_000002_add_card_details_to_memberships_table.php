<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('memberships')) {
            return;
        }

        Schema::table('memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('memberships', 'card_last_four')) {
                $table->string('card_last_four', 4)->nullable()->after('payment_method_label');
            }
            if (! Schema::hasColumn('memberships', 'card_type')) {
                $table->string('card_type', 20)->nullable()->after('card_last_four');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('memberships')) {
            return;
        }

        Schema::table('memberships', function (Blueprint $table) {
            foreach (['card_type', 'card_last_four'] as $column) {
                if (Schema::hasColumn('memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
