<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            if (Schema::hasColumn('notifications', 'customer_id')) {
                $table->dropForeign(['customer_id']);
            }

            $table->dropIndex(['user_id', 'user_type']); // Composite index
            $table->dropIndex(['customer_id']);

            $table->dropColumn(['customer_id', 'user_type', 'related_user', 'related_location']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('location_id')->after('id')->constrained()->onDelete('cascade');

            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');

            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->enum('user_type', ['admin', 'customer'])->after('customer_id');
            $table->string('related_user')->nullable()->after('metadata');
            $table->string('related_location')->nullable()->after('related_user');

            $table->index('customer_id');
        });
    }
};
