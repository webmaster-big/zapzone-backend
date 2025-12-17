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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('guest_of_honor_name')->nullable()->after('special_requests');
            $table->integer('guest_of_honor_age')->nullable()->after('guest_of_honor_name');
            $table->enum('guest_of_honor_gender', ['male', 'female', 'other'])->nullable()->after('guest_of_honor_age');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['guest_of_honor_name', 'guest_of_honor_age', 'guest_of_honor_gender']);
        });
    }
};
