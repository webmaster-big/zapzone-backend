<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // hashed, never stored or returned in the clear
            $table->string('override_pin')->nullable()->after('password');
            $table->timestamp('override_pin_set_at')->nullable()->after('override_pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['override_pin', 'override_pin_set_at']);
        });
    }
};
