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
        Schema::create('attraction_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attraction_id')->constrained()->onDelete('cascade');
            $table->foreignId('add_on_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index('attraction_id');
            $table->index('add_on_id');
            $table->unique(['attraction_id', 'add_on_id']);
        });

        Schema::table('attractions', function (Blueprint $table) {
            $table->json('add_ons_order')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attraction_add_ons');

        Schema::table('attractions', function (Blueprint $table) {
            $table->dropColumn('add_ons_order');
        });
    }
};
