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

            // Tenancy (resolved server-side from entity when possible)
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            // Visitor identity
            $table->string('visitor_id', 64)->nullable();   // long-lived anonymous cookie
            $table->string('session_id', 64)->nullable();   // per-session token
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Where the event came from (web | mobile_app | email | server)
            $table->string('source', 16)->default('web');

            // Idempotency: FE can send a UUID per conversion to prevent
            // duplicate counting if the same call is retried. Server-side
            // hooks also set this so the FE's optional belt-and-suspenders
            // call is deduped.
            $table->string('tracking_id', 64)->nullable()->unique();

            // Event taxonomy
            // event_type: page_view | conversion | engagement
            $table->string('event_type', 32)->default('page_view');
            // event_name: page_view | booking_started | booking_completed | purchase_completed |
            //             event_purchase_completed | add_to_cart | signup | login | search | etc.
            $table->string('event_name', 64)->default('page_view');

            // page_type: home | package_list | package_detail | attraction_list | attraction_detail |
            //            event_list | event_detail | booking_form | booking_confirmation | checkout |
            //            customer_login | customer_register | customer_dashboard | purchase_detail | other
            $table->string('page_type', 64)->nullable();

            // Page info
            $table->text('page_url')->nullable();
            $table->string('page_path', 500)->nullable();
            $table->string('page_title', 500)->nullable();
            $table->text('referrer')->nullable();

            // Linked entity (e.g. the package/attraction/event being viewed,
            // or the booking/purchase that was created on conversion)
            $table->string('entity_type', 64)->nullable(); // package | attraction | event | booking | attraction_purchase | event_purchase
            $table->unsignedBigInteger('entity_id')->nullable();

            // Conversion value
            $table->decimal('conversion_value', 12, 2)->nullable();
            $table->string('currency', 8)->nullable()->default('USD');

            // Marketing attribution
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->string('utm_content', 150)->nullable();

            // First-touch attribution — copied from the visitor's earliest
            // recorded event onto every subsequent event, so we can answer
            // "which campaign originally brought this converting visitor?".
            $table->string('first_touch_source', 100)->nullable();
            $table->string('first_touch_medium', 100)->nullable();
            $table->string('first_touch_campaign', 150)->nullable();

            // Tech / device
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_type', 16)->nullable(); // desktop | mobile | tablet | bot
            $table->string('browser', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('language', 16)->nullable();

            // Engagement
            $table->unsignedInteger('duration_ms')->nullable(); // patched on page leave
            $table->unsignedSmallInteger('scroll_depth')->nullable(); // 0-100

            // Visitor / session classification (cheap booleans for fast aggregates)
            // Note: bounce is intentionally computed inline in queries
            // (sessions with exactly 1 page_view) to avoid the race of
            // updating an older row when the 2nd page-view arrives.
            $table->boolean('is_new_visitor')->default(false)->index();
            $table->boolean('is_landing')->default(false)->index();

            // Anything extra
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes — designed for typical analytics queries
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
