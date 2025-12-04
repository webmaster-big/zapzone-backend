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
        Schema::table('activity_logs', function (Blueprint $table) {
            // Add entity tracking fields
            $table->string('entity_type')->nullable()->after('action'); // booking, payment, customer, etc.
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type'); // ID of the entity

            // Add action category
            $table->enum('category', ['create', 'update', 'delete', 'view', 'login', 'logout', 'export', 'import', 'other'])
                  ->default('other')
                  ->after('action');

            // Add metadata for additional context
            $table->json('metadata')->nullable()->after('user_agent');

            // Rename 'details' to 'description' for clarity
            $table->renameColumn('details', 'description');

            // Add indexes for performance
            $table->index('entity_type');
            $table->index('entity_id');
            $table->index('category');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['activity_logs_entity_type_index']);
            $table->dropIndex(['activity_logs_entity_id_index']);
            $table->dropIndex(['activity_logs_category_index']);
            $table->dropIndex(['activity_logs_entity_type_entity_id_index']);

            $table->dropColumn('entity_type');
            $table->dropColumn('entity_id');
            $table->dropColumn('category');
            $table->dropColumn('metadata');

            $table->renameColumn('description', 'details');
        });
    }
};
