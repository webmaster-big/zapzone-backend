<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // Membership Plans
        // -------------------------------------------------------------------
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // home / default location for plan; null = company-wide
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->json('benefits')->nullable(); // free-form list of bullet benefits

            $table->enum('tier', ['basic', 'premium', 'unlimited', 'family', 'discounted', 'comped', 'custom'])->default('basic');

            $table->decimal('price', 10, 2)->default(0);
            $table->enum('billing_cycle', ['monthly', 'annual', 'custom'])->default('monthly');
            $table->unsignedInteger('custom_billing_days')->nullable();

            // Usage rules
            $table->enum('usage_type', ['limited', 'unlimited'])->default('unlimited');
            $table->unsignedInteger('uses_per_term')->nullable();
            $table->unsignedInteger('visits_per_term')->nullable();
            $table->unsignedInteger('services_per_term')->nullable();
            $table->boolean('unlimited_uses_per_term')->default(false);
            $table->boolean('unlimited_visits_per_term')->default(false);
            $table->unsignedInteger('max_visits_per_day')->nullable();

            // Booking rules
            $table->boolean('member_only_booking')->default(false);
            $table->unsignedInteger('advance_booking_days')->default(0);
            $table->boolean('late_cancel_counts_as_visit')->default(false);
            $table->boolean('no_show_counts_as_visit')->default(false);

            // Location access mode
            $table->enum('location_access_mode', ['single', 'multi', 'all'])->default('single');

            // Lifecycle rules
            $table->unsignedInteger('grace_period_days')->default(5);
            $table->unsignedInteger('failed_payment_retry_days')->default(3);
            $table->unsignedInteger('failed_payment_max_retries')->default(3);
            $table->enum('cancellation_mode', ['immediate', 'end_of_term', 'staff_only'])->default('end_of_term');
            $table->boolean('renewable')->default(true);

            // Optional discount % applied to bookings for members
            $table->decimal('discount_percent', 5, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index('location_id');
        });

        // Approved locations for multi-location plans
        Schema::create('membership_plan_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['membership_plan_id', 'location_id'], 'mpl_unique');
        });

        // -------------------------------------------------------------------
        // Membership Groups (family / group plans)
        // -------------------------------------------------------------------
        Schema::create('membership_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payer_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name')->nullable(); // e.g. "Family"
            $table->timestamps();
            $table->index('payer_customer_id');
        });

        // -------------------------------------------------------------------
        // Memberships
        // -------------------------------------------------------------------
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('home_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('sold_at_location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->enum('status', [
                'pending', 'active', 'past_due', 'suspended',
                'frozen', 'canceled', 'expired',
            ])->default('pending');

            // Lifecycle dates
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_term_start')->nullable();
            $table->timestamp('current_term_end')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('cancellation_effective_at')->nullable();
            $table->timestamp('frozen_until')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();

            // Usage counters (current term)
            $table->unsignedInteger('uses_remaining')->nullable();
            $table->unsignedInteger('visits_remaining')->nullable();
            $table->unsignedInteger('services_remaining')->nullable();

            // Photo (taken in-store, staff-only)
            $table->string('photo_path')->nullable();
            $table->timestamp('photo_taken_at')->nullable();
            $table->foreignId('photo_taken_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // QR check-in (opaque)
            $table->string('qr_token', 64)->unique();

            // Billing
            $table->decimal('billing_amount', 10, 2)->default(0);
            $table->string('payment_method_label')->nullable(); // e.g. "Visa •••• 4242"
            $table->string('payment_profile_token')->nullable(); // Authorize.Net Customer Payment Profile ID
            $table->boolean('recurring_billing_authorized')->default(false);
            $table->timestamp('recurring_billing_authorized_at')->nullable();
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();

            $table->boolean('is_comped')->default(false);
            $table->decimal('discount_amount', 10, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index('status');
            $table->index('next_billing_at');
            $table->index('home_location_id');
        });

        // -------------------------------------------------------------------
        // Visits (check-in history)
        // -------------------------------------------------------------------
        Schema::create('membership_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('visited_at');
            $table->enum('result', ['allowed', 'denied', 'override'])->default('allowed');
            $table->string('denial_reason')->nullable();
            $table->boolean('counted_against_usage')->default(true);
            $table->unsignedInteger('visits_remaining_after')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['membership_id', 'visited_at']);
            $table->index('location_id');
        });

        // -------------------------------------------------------------------
        // Payments / Billing history
        // -------------------------------------------------------------------
        Schema::create('membership_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded', 'voided'])->default('pending');
            $table->string('transaction_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('retry_attempt')->default(0);
            $table->timestamp('charged_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['membership_id', 'status']);
        });

        // -------------------------------------------------------------------
        // Staff notes on memberships
        // -------------------------------------------------------------------
        Schema::create('membership_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', [
                'general', 'billing', 'access', 'manual_override',
                'cancellation', 'internal_warning',
            ])->default('general');
            $table->text('content');
            $table->boolean('pinned')->default(false);
            $table->enum('visibility', ['staff', 'manager_only'])->default('staff');
            $table->timestamps();
            $table->index(['membership_id', 'pinned']);
        });

        // -------------------------------------------------------------------
        // Audit log (every state-changing membership action)
        // -------------------------------------------------------------------
        Schema::create('membership_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // e.g. status_change, plan_change, photo_update, override, cancel
            $table->string('actor_type')->default('staff'); // staff | customer | system
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['membership_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_audit_logs');
        Schema::dropIfExists('membership_notes');
        Schema::dropIfExists('membership_payments');
        Schema::dropIfExists('membership_visits');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_groups');
        Schema::dropIfExists('membership_plan_locations');
        Schema::dropIfExists('membership_plans');
    }
};
