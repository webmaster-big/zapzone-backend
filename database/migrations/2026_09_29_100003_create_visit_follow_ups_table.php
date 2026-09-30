<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visit_follow_ups')) {
            return;
        }

        Schema::create('visit_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visit_type', 32);
            $table->unsignedBigInteger('visit_id');
            $table->string('kind', 16);
            $table->foreignId('email_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('waiver_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('recipient_email', 190);
            $table->string('recipient_name', 190)->nullable();
            $table->string('status', 16);
            $table->timestamp('due_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('token', 64)->nullable()->unique();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->unsignedBigInteger('alert_notification_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['visit_type', 'visit_id', 'kind', 'recipient_email'], 'visit_follow_ups_recipient_unique');
            $table->index(['status', 'due_at'], 'visit_follow_ups_status_due_index');
            $table->index(['company_id', 'kind', 'recipient_email'], 'visit_follow_ups_recipient_index');
            $table->index(['location_id', 'rated_at'], 'visit_follow_ups_location_rated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_follow_ups');
    }
};
