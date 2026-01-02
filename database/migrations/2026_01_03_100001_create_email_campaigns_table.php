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
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('email_template_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('sent_by')->constrained('users')->onDelete('cascade');
            $table->string('name'); // Campaign name
            $table->string('subject');
            $table->longText('body'); // The actual body used (with variables)
            $table->json('recipient_types'); // ['customers', 'attendants', 'company_admin', 'custom']
            $table->json('custom_emails')->nullable(); // Array of custom email addresses
            $table->json('recipient_filters')->nullable(); // Filters like status, location, etc.
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->enum('status', ['pending', 'sending', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->timestamp('scheduled_at')->nullable(); // For scheduled campaigns
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['location_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
