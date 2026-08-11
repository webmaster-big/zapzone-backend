<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_photo_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('kiosk_enabled')->default(true);
            $table->boolean('slideshow_enabled')->default(true);
            $table->text('kiosk_passcode')->nullable();
            $table->text('slideshow_passcode')->nullable();
            $table->unsignedSmallInteger('slideshow_duration_seconds')->default(8);
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->string('date_format', 32)->default('M j, Y');
            $table->string('date_position', 20)->default('bottom_right');
            $table->unsignedSmallInteger('date_font_size')->default(34);
            $table->unsignedSmallInteger('date_margin')->default(28);
            $table->string('date_background', 12)->default('solid');
            $table->string('failure_notify_email')->nullable();
            $table->timestamp('slideshow_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('photo_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('image_path');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'is_enabled', 'starts_at'], 'photo_overlays_active_idx');
        });

        Schema::create('slideshow_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('operating_day');
            $table->string('status', 12)->default('active');
            $table->boolean('is_paused')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['location_id', 'operating_day'], 'slideshow_queues_day_unique');
            $table->index(['location_id', 'status'], 'slideshow_queues_status_idx');
        });

        Schema::create('photo_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12);
            $table->string('status', 24)->default('in_progress');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verbal_consent_at')->nullable();
            $table->string('delivery_method', 24)->nullable();
            $table->string('delivery_schedule', 16)->nullable();
            $table->boolean('slideshow_opt_in')->default(false);
            $table->string('access_token', 64)->unique();
            $table->string('qr_token', 64)->unique();
            $table->timestamp('qr_expires_at')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->date('capture_date')->nullable();
            $table->date('operating_day')->nullable();
            $table->string('kiosk_contact_name')->nullable();
            $table->string('kiosk_contact_email')->nullable();
            $table->string('kiosk_contact_phone', 40)->nullable();
            $table->boolean('kiosk_marketing_consent')->nullable();
            $table->timestamp('kiosk_contact_at')->nullable();
            $table->unsignedInteger('qr_scan_count')->default(0);
            $table->timestamp('first_scanned_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'operating_day'], 'photo_sessions_day_idx');
            $table->index(['location_id', 'source', 'status'], 'photo_sessions_state_idx');
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slideshow_queue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('photo_overlay_id')->nullable()->constrained('photo_overlays')->nullOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('source', 12);
            $table->string('processing_status', 12)->default('pending');
            $table->text('processing_error')->nullable();
            $table->string('original_path')->nullable();
            $table->string('delivery_path')->nullable();
            $table->string('slideshow_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->boolean('slideshow_eligible')->default(false);
            $table->string('slideshow_state', 12)->default('visible');
            $table->integer('slideshow_priority')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->date('capture_date')->nullable();
            $table->date('operating_day')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'operating_day'], 'photos_day_idx');
            $table->index(['slideshow_queue_id', 'slideshow_state'], 'photos_queue_idx');
        });

        Schema::create('photo_session_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiver_id')->constrained()->cascadeOnDelete();
            $table->string('contact_name')->nullable();
            $table->timestamps();
            $table->unique(['photo_session_id', 'waiver_id'], 'photo_session_waivers_unique');
        });

        Schema::create('photo_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('photo_deliveries')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('channel', 8);
            $table->string('destination');
            $table->string('recipient_name')->nullable();
            $table->string('status', 12)->default('queued');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_for'], 'photo_deliveries_due_idx');
            $table->index(['location_id', 'created_at'], 'photo_deliveries_loc_idx');
        });

        Schema::create('photo_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('email_subject');
            $table->text('email_body');
            $table->text('sms_body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'kind'], 'photo_message_templates_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_message_templates');
        Schema::dropIfExists('photo_deliveries');
        Schema::dropIfExists('photo_session_waivers');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('photo_sessions');
        Schema::dropIfExists('slideshow_queues');
        Schema::dropIfExists('photo_overlays');
        Schema::dropIfExists('location_photo_settings');
    }
};
