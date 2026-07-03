<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_bulk_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('waiver_template_id')->constrained()->onDelete('cascade');

            $table->date('selected_date');
            $table->string('chaperone_name');
            $table->string('chaperone_email')->nullable();
            $table->string('chaperone_phone')->nullable();

            $table->string('manage_token', 64)->unique();
            $table->string('shareable_token', 64)->nullable()->unique();
            $table->boolean('allow_shareable_link')->default(false);

            $table->enum('status', ['not_sent', 'sent'])->default('not_sent');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('location_id');
            $table->index('selected_date');
        });

        Schema::create('waiver_invite_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_bulk_invite_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->enum('status', ['not_sent', 'sent', 'complete', 'not_complete', 'failed'])->default('not_sent');
            $table->string('invite_token', 64)->unique();
            $table->foreignId('waiver_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('resent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('waiver_bulk_invite_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_invite_recipients');
        Schema::dropIfExists('waiver_bulk_invites');
    }
};
