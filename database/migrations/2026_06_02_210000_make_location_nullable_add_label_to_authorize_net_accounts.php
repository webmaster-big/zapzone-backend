<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorize_net_accounts', function (Blueprint $table) {
            // Drop the existing unique+FK constraint on location_id
            $table->dropForeign(['location_id']);
            $table->dropUnique(['location_id']);

            // Make location_id nullable (centralized accounts have no location)
            $table->unsignedBigInteger('location_id')->nullable()->change();

            // Re-add FK (nullable) and a unique index that still prevents duplicate per-location accounts
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->unique('location_id');

            // Label for centralized accounts (e.g. "Company Central", "Membership Gateway")
            $table->string('label', 100)->nullable()->after('location_id')
                ->comment('Human-readable name for centralized (no-location) accounts.');
        });
    }

    public function down(): void
    {
        Schema::table('authorize_net_accounts', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropUnique(['location_id']);
            $table->dropColumn('label');
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->unique('location_id');
        });
    }
};
