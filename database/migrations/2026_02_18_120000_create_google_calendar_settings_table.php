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
        Schema::create('google_calendar_settings', function (Blueprint $table) {
            $table->id();
            $table->text('client_id')->nullable()->comment('Google OAuth Client ID (stored in DB so no env needed on Forge)');
            $table->text('client_secret')->nullable()->comment('Google OAuth Client Secret');
            $table->string('frontend_redirect_url')->nullable()->comment('Frontend URL to redirect after OAuth');
            $table->string('google_account_email')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->date('sync_from_date')->nullable()->comment('Only sync bookings from this date onwards');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_calendar_settings');
    }
};
