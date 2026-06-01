<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('visitor_id', 64)->nullable();   // long-lived anonymous cookie
            $table->string('session_id', 64)->nullable();   // per-session token
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('source', 16)->default('web');

            $table->string('tracking_id', 64)->nullable()->unique();

            $table->string('event_type', 32)->default('page_view');
            $table->string('event_name', 64)->default('page_view');

            $table->string('page_type', 64)->nullable();

            $table->text('page_url')->nullable();
            $table->string('page_path', 500)->nullable();
            $table->string('page_title', 500)->nullable();
            $table->text('referrer')->nullable();

            $table->string('entity_type', 64)->nullable(); // package | attraction | event | booking | attraction_purchase | event_purchase
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->decimal('conversion_value', 12, 2)->nullable();
            $table->string('currency', 8)->nullable()->default('USD');

            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->string('utm_content', 150)->nullable();

            $table->string('first_touch_source', 100)->nullable();
            $table->string('first_touch_medium', 100)->nullable();
            $table->string('first_touch_campaign', 150)->nullable();

            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_type', 16)->nullable(); // desktop | mobile | tablet | bot
            $table->string('browser', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('language', 16)->nullable();

            $table->unsignedInteger('duration_ms')->nullable(); // patched on page leave
            $table->unsignedSmallInteger('scroll_depth')->nullable(); // 0-100

            $table->boolean('is_new_visitor')->default(false)->index();
            $table->boolean('is_landing')->default(false)->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'location_id', 'created_at'], 'pv_tenant_time_idx');
            $table->index(['event_type', 'event_name', 'created_at'], 'pv_event_time_idx');
            $table->index(['entity_type', 'entity_id'], 'pv_entity_idx');
            $table->index(['page_type', 'created_at'], 'pv_page_time_idx');
            $table->index(['source', 'created_at'], 'pv_source_time_idx');
            $table->index('session_id');
            $table->index('visitor_id');
            $table->index('utm_source');
            $table->index('utm_campaign');
            $table->index('first_touch_source');
            $table->index('first_touch_campaign');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
