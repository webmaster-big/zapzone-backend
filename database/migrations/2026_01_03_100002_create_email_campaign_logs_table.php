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
        Schema::create('email_campaign_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->onDelete('cascade');
            $table->string('recipient_email');
            $table->string('recipient_type'); // 'customer', 'attendant', 'company_admin', 'custom'
            $table->unsignedBigInteger('recipient_id')->nullable(); // ID of customer/user if applicable
            $table->enum('status', ['pending', 'sent', 'failed', 'bounced', 'opened', 'clicked'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('variables_used')->nullable(); // Store the actual variable values used
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->index(['email_campaign_id', 'status']);
            $table->index('recipient_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaign_logs');
    }
};
