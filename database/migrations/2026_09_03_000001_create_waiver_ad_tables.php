<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_template_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiver_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->comment('null location = shows at all of the company\'s locations')->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('image_path');
            $table->string('destination_url', 2048)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_fallback')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['waiver_template_id', 'is_enabled', 'starts_at'], 'waiver_template_ads_eligible_idx');
        });

        Schema::create('waiver_ad_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_template_ad_id')->constrained('waiver_template_ads')->cascadeOnDelete();
            $table->unsignedBigInteger('waiver_id')->nullable()->index();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('event', 40)->default('displayed');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['waiver_template_ad_id', 'created_at'], 'waiver_ad_events_ad_time_idx');
        });

        Schema::create('waiver_ad_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_template_ad_id')->constrained('waiver_template_ads')->cascadeOnDelete();
            $table->unsignedBigInteger('waiver_id');
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->enum('channel', ['email', 'sms']);
            $table->string('destination')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['waiver_id', 'waiver_template_ad_id', 'channel'], 'waiver_ad_sends_once_idx');
            $table->index(['waiver_template_ad_id', 'created_at'], 'waiver_ad_sends_ad_time_idx');
        });

        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->boolean('ads_enabled')->default(false)->after('reminder_eligible');
            $table->enum('ads_rotation_mode', ['random', 'ordered'])->default('random')->after('ads_enabled');
            $table->unsignedTinyInteger('ads_display_seconds')->default(3)->after('ads_rotation_mode');
            $table->unsignedBigInteger('ads_rotation_counter')->default(0)->after('ads_display_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->dropColumn(['ads_enabled', 'ads_rotation_mode', 'ads_display_seconds', 'ads_rotation_counter']);
        });
        Schema::dropIfExists('waiver_ad_sends');
        Schema::dropIfExists('waiver_ad_events');
        Schema::dropIfExists('waiver_template_ads');
    }
};
