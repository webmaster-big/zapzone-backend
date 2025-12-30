<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration converts the booking_id column to a polymorphic relationship
     * that can handle both bookings (packages) and attraction purchases.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Add payable_type column to store the type of entity (booking or attraction_purchase)
            $table->string('payable_type')->nullable()->after('id');

            // Rename booking_id to payable_id for polymorphic relationship
            // First, drop the foreign key constraint
            $table->dropForeign(['booking_id']);
            $table->dropIndex(['booking_id']);
        });

        // Rename the column
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('booking_id', 'payable_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Add index for polymorphic relationship
            $table->index(['payable_type', 'payable_id']);
        });

        // Update existing records - set payable_type to 'booking' for existing payments with payable_id
        DB::table('payments')
            ->whereNotNull('payable_id')
            ->update(['payable_type' => 'booking']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, drop the polymorphic index
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
        });

        // Rename column back
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('payable_id', 'booking_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Drop the payable_type column
            $table->dropColumn('payable_type');

            // Re-add foreign key constraint and index
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
            $table->index('booking_id');
        });
    }
};
