<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');

            $table->foreignId('waiver_template_id')->constrained()->onDelete('cascade');
            $table->foreignId('waiver_template_version_id')->constrained()->onDelete('cascade');

            // links (all optional — a waiver may stand alone)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attraction_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('bulk_invite_id')->nullable();
            $table->unsignedBigInteger('bulk_invite_recipient_id')->nullable();

            $table->enum('status', ['pending', 'completed', 'expired', 'replaced', 'deleted'])->default('pending');
            $table->date('selected_date');

            // activity name captured at assign time when the waiver isn't tied to a concrete
            // booking/event/purchase (e.g. manager-assigned). Drives {{activity_name}} autofill.
            $table->string('manual_activity_name')->nullable();

            // adult / guardian
            $table->string('adult_first_name')->nullable();
            $table->string('adult_last_name')->nullable();
            $table->string('adult_email')->nullable();
            $table->string('adult_phone')->nullable();
            $table->date('adult_dob')->nullable();
            $table->string('relationship')->nullable();
            $table->string('typed_legal_name')->nullable();

            // agreements
            $table->boolean('agreement_accepted')->default(false);
            $table->boolean('electronic_consent_accepted')->default(false);
            $table->boolean('photo_video_consent')->nullable();

            // marketing consent — stored separately, never gates submission
            $table->enum('marketing_consent_status', ['not_opted_in', 'opted_in', 'withdrawn'])->default('not_opted_in');
            $table->timestamp('marketing_consent_at')->nullable();
            $table->string('marketing_consent_source')->nullable();

            // capture
            $table->enum('source', ['checkout', 'confirmation_email', 'sms_link', 'kiosk', 'staff_sent', 'bulk_invite'])->default('checkout');
            $table->string('ip_address')->nullable();
            $table->string('device')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('reminder_sent')->default(false);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->foreignId('replaced_by_waiver_id')->nullable()->constrained('waivers')->nullOnDelete();

            $table->string('access_token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_manager_assigned')->default(false);

            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('location_id');
            $table->index('selected_date');
            $table->index('status');
            $table->index('customer_id');
            $table->index('booking_id');
            $table->index('event_id');
            $table->index('attraction_purchase_id');
            $table->index(['status', 'reminder_sent']);
            $table->index('adult_email');
            $table->index('adult_phone');
            $table->index('submitted_at');
            $table->index(['bulk_invite_id', 'bulk_invite_recipient_id']);
        });

        Schema::create('waiver_minors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_id')->constrained()->onDelete('cascade');
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('relationship')->nullable();
            $table->timestamps();

            $table->index('waiver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_minors');
        Schema::dropIfExists('waivers');
    }
};
