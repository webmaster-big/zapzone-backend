<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->foreignId('purchased_by_customer_id')->nullable()->after('created_by')->constrained('customers')->nullOnDelete();
            $table->string('purchaser_name')->nullable()->after('purchased_by_customer_id');
            $table->string('purchaser_email')->nullable()->after('purchaser_name');
            $table->string('purchaser_phone', 40)->nullable()->after('purchaser_email');
            $table->timestamp('purchased_at')->nullable()->after('purchaser_phone');
            $table->decimal('purchase_amount', 10, 2)->nullable()->after('purchased_at');
            $table->index('purchased_at');
        });

        DB::statement('ALTER TABLE gift_cards MODIFY created_by BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropIndex(['purchased_at']);
            $table->dropForeign(['purchased_by_customer_id']);
            $table->dropColumn([
                'purchased_by_customer_id',
                'purchaser_name',
                'purchaser_email',
                'purchaser_phone',
                'purchased_at',
                'purchase_amount',
            ]);
        });
    }
};
