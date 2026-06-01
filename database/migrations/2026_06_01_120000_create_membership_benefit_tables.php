<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plan_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();

            $table->enum('benefit_type', [
                'package_discount',
                'attraction_discount',
                'event_discount',
                'addon_discount',
                'free_entry_pass',
                'guest_pass',
                'priority_booking',
                'member_only_access',
                'birthday_reward',
            ]);

            $table->string('label')->nullable(); // human-friendly description for UI

            $table->enum('scope_type', ['any', 'package', 'attraction', 'event', 'category', 'location'])
                  ->default('any');
            $table->unsignedBigInteger('scope_id')->nullable(); // target entity id (null = all)
            $table->string('scope_category')->nullable();        // when scope_type = category

            $table->enum('value_mode', ['percent', 'fixed', 'free', 'count', 'flag'])->default('percent');
            $table->decimal('value', 10, 2)->default(0); // 15.00 = 15% or $15, or 4 = 4 passes

            $table->enum('period', ['per_term', 'per_day', 'per_visit', 'lifetime', 'once'])
                  ->default('per_term');
            $table->unsignedInteger('max_redemptions')->nullable(); // cap per period (null = unlimited)

            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_stackable')->default(false);

            $table->json('conditions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['membership_plan_id', 'is_active']);
            $table->index(['benefit_type', 'scope_type']);
        });

        Schema::create('membership_benefit_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_benefit_id')->nullable();
            $table->foreign('membership_plan_benefit_id', 'mbr_redemptions_plan_benefit_fk')
                  ->references('id')->on('membership_plan_benefits')->nullOnDelete();

            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('benefit_type');
            $table->enum('value_mode', ['percent', 'fixed', 'free', 'count', 'flag'])->default('percent');
            $table->decimal('value_applied', 10, 2)->default(0); // money discounted OR count consumed

            $table->string('redeemable_type')->nullable();
            $table->unsignedBigInteger('redeemable_id')->nullable();

            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete(); // null = system/online

            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            $table->timestamps();

            $table->index(['membership_id', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['redeemable_type', 'redeemable_id']);
            $table->index(['membership_plan_benefit_id', 'reversed_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->after('promo_id')
                  ->constrained('memberships')->nullOnDelete();
            $table->index('membership_id');
        });

        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->after('customer_id')
                  ->constrained('memberships')->nullOnDelete();
            $table->index('membership_id');
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->after('customer_id')
                  ->constrained('memberships')->nullOnDelete();
            $table->index('membership_id');
        });

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->foreignId('inherits_plan_id')->nullable()->after('tier')
                  ->constrained('membership_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inherits_plan_id');
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_id');
        });

        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_id');
        });

        Schema::dropIfExists('membership_benefit_redemptions');
        Schema::dropIfExists('membership_plan_benefits');
    }
};
