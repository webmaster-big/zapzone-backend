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
        if (!Schema::hasTable('email_notifications')) {
            Schema::create('email_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('name'); // Friendly name for the notification
                
                // Trigger type - stored as string to allow flexibility for future additions
                // See EmailNotification::TRIGGER_TYPES for full list
                $table->string('trigger_type')->default('booking_created');
                
                $table->enum('entity_type', ['package', 'attraction', 'all'])->default('all'); // What entity type this applies to
                $table->json('entity_ids')->nullable(); // Array of package_ids or attraction_ids (null = all)
                $table->foreignId('email_template_id')->nullable()->constrained()->onDelete('set null');
                $table->string('subject')->nullable(); // Custom subject if not using template
                $table->longText('body')->nullable(); // Custom body if not using template
                $table->json('recipient_types'); // ['customer', 'staff', 'company_admin', 'location_manager', 'custom']
                $table->json('custom_emails')->nullable(); // Custom email addresses
                $table->boolean('include_qr_code')->default(true); // Whether to include QR code in email
                $table->boolean('is_active')->default(true);
                
                // Timing settings for reminder-type notifications
                $table->integer('send_before_hours')->nullable(); // For reminders: how many hours before
                $table->integer('send_after_hours')->nullable(); // For follow-ups: how many hours after
                
                $table->timestamps();

                // Indexes
                $table->index(['company_id', 'is_active']);
                $table->index(['trigger_type', 'entity_type']);
                $table->index('trigger_type');
            });
        }

        // Log table for tracking sent notifications
        if (!Schema::hasTable('email_notification_logs')) {
            Schema::create('email_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_notification_id')->constrained()->onDelete('cascade');
            $table->string('recipient_email');
            $table->string('recipient_type'); // customer, staff, company_admin, location_manager, custom
            $table->morphs('notifiable'); // booking_id or attraction_purchase_id
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['email_notification_id', 'status']);
            $table->index(['notifiable_type', 'notifiable_id']);
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_notification_logs');
        Schema::dropIfExists('email_notifications');
    }
};
