<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Walk-in members are frequently accepted in person without an email or phone
     * on file. Staff still need to create their membership record, so both columns
     * become nullable. The unique index on `email` is retained — MySQL treats NULLs
     * as distinct, so any number of email-less customers can coexist while real
     * emails stay unique (and usable as a login identifier).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('phone')->nullable(false)->change();
        });
    }
};
