<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->onDelete('cascade');

            $table->unsignedInteger('default_validity_days')->nullable();
            $table->boolean('waivers_expire')->default(false);
            $table->unsignedInteger('default_expiration_days')->nullable();
            $table->boolean('require_new_on_text_change')->default(true);
            $table->enum('default_duplicate_rule', ['none', 'allow', 'manager_only'])->default('manager_only');

            $table->unsignedInteger('reminder_window_hours')->default(24);
            $table->boolean('always_include_link_in_confirmation')->default(true);
            $table->unsignedInteger('search_auto_refresh_seconds')->default(30); // 0 = off
            $table->unsignedInteger('kiosk_inactivity_timeout_seconds')->default(60);
            $table->boolean('kiosk_disable_autofill')->default(true);

            $table->boolean('admin_delete_enabled')->default(true);
            $table->boolean('manager_print_export_enabled')->default(true);
            $table->boolean('manager_can_build_templates')->default(false);
            $table->boolean('manager_can_view_deletion_log')->default(false);

            $table->boolean('marketing_consent_enabled')->default(true);
            $table->boolean('crm_sync_only_when_consented')->default(true);
            $table->boolean('minor_marketing_disabled')->default(true);

            $table->timestamps();
        });

        Schema::create('waiver_deletion_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            // intentionally no FK — the waiver row may be force-deleted later
            $table->unsignedBigInteger('waiver_id');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamps();

            $table->index('company_id');
            $table->index('waiver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_deletion_log');
        Schema::dropIfExists('waiver_settings');
    }
};
