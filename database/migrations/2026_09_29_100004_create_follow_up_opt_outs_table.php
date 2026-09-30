<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('follow_up_opt_outs')) {
            return;
        }

        Schema::create('follow_up_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->unsignedBigInteger('visit_follow_up_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'email'], 'follow_up_opt_outs_company_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_opt_outs');
    }
};
