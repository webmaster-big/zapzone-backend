<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || Schema::hasColumn('payments', 'card_type')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('card_type', 20)->nullable()->after('card_last_four');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'card_type')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('card_type');
        });
    }
};
