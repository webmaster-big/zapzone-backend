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
        Schema::create('booking_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->enum('send_via', ['email', 'text', 'both'])->default('email');
            $table->string('rsvp_token', 64)->unique();
            $table->enum('rsvp_status', ['pending', 'attending', 'declined'])->default('pending');
            $table->string('rsvp_full_name')->nullable();
            $table->string('rsvp_email')->nullable();
            $table->string('rsvp_phone')->nullable();
            $table->integer('rsvp_guest_count')->nullable();
            $table->text('rsvp_notes')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'rsvp_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_invitations');
    }
};
