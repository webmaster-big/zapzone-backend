<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['customer_id']);
            
            // Drop foreign key constraint for customer_id if it exists
            if (Schema::hasColumn('notifications', 'customer_id')) {
                $table->dropForeign(['customer_id']);
            }

            // Drop old columns
            $table->dropColumn(['customer_id', 'user_type', 'related_user', 'related_location']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            // Add location_id
            $table->foreignId('location_id')->after('id')->constrained()->onDelete('cascade');

            // Add index for location_id
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Remove location_id
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');

            // Restore old columns
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->enum('user_type', ['admin', 'customer'])->after('customer_id');
            $table->string('related_user')->nullable()->after('metadata');
            $table->string('related_location')->nullable()->after('related_user');

            // Restore index
            $table->index('customer_id');
        });
    }
};
