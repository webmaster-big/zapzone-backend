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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->unique();
            $table->string('email')->unique();
            $table->string('phone');
            $table->text('address');
            $table->integer('total_locations')->default(0);
            $table->integer('total_employees')->default(0);
            // $table->enum('subscription_plan', ['basic', 'premium', 'enterprise'])->default('basic');
            // $table->enum('subscription_status', ['active', 'inactive', 'trial'])->default('trial');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
