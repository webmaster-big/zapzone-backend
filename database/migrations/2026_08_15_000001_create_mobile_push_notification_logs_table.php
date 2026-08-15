<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mobile_push_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expo_push_token');
            $table->string('status', 12)->default('pending');
            $table->string('ticket_id')->nullable();
            $table->string('receipt_status', 12)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['notification_id', 'status'], 'mobile_push_logs_notification_idx');
            $table->index(['status', 'receipt_status'], 'mobile_push_logs_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_notification_logs');
    }
};
