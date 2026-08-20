<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('pricing_type', 20)->default('base')->after('price');
            $table->unsignedInteger('max_tickets_per_slot')->nullable()->after('max_participants');
            $table->string('participant_label', 50)->nullable()->after('max_tickets_per_slot');
            $table->string('display_label', 100)->nullable()->after('participant_label');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'max_tickets_per_slot', 'participant_label', 'display_label']);
        });
    }
};
