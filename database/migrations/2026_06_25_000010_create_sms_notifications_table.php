<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sms_notifications')) {
            Schema::create('sms_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('name');
                $table->text('description')->nullable();

                $table->string('trigger_type')->default('booking_confirmed');

                $table->enum('entity_type', ['package', 'attraction', 'event', 'all'])->default('all');
                $table->json('entity_ids')->nullable();

                $table->text('body')->nullable();
                $table->text('default_body')->nullable();

                $table->json('recipient_types');
                $table->json('custom_phones')->nullable();

                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->string('default_key')->nullable();

                $table->integer('send_before_hours')->nullable();
                $table->integer('send_after_hours')->nullable();

                $table->timestamps();

                $table->index(['company_id', 'is_active']);
                $table->index(['trigger_type', 'entity_type']);
                $table->index('trigger_type');
                $table->index(['company_id', 'default_key']);
            });
        }

        if (!Schema::hasTable('sms_notification_logs')) {
            Schema::create('sms_notification_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sms_notification_id')->constrained()->onDelete('cascade');
                $table->string('recipient_phone');
                $table->string('recipient_type');
                $table->morphs('notifiable');
                $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
                $table->unsignedSmallInteger('segments')->nullable();
                $table->string('provider_sid')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['sms_notification_id', 'status']);
                $table->index(['notifiable_type', 'notifiable_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_notification_logs');
        Schema::dropIfExists('sms_notifications');
    }
};
