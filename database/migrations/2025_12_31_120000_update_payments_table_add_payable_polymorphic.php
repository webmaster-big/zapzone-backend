<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payable_type')->nullable()->after('id');

            $table->dropForeign(['booking_id']);
            $table->dropIndex(['booking_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('booking_id', 'payable_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['payable_type', 'payable_id']);
        });

        DB::table('payments')
            ->whereNotNull('payable_id')
            ->update(['payable_type' => 'booking']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('payable_id', 'booking_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payable_type');

            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
            $table->index('booking_id');
        });
    }
};
