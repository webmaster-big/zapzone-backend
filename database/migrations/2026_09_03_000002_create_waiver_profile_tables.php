<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('phone_digits', 20)->comment('lookup key: digits only, US leading 1 stripped');
            $table->string('phone_e164', 20)->nullable();
            $table->string('phone_raw', 30)->nullable();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->boolean('needs_staff_review')->default(false);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamp('last_waiver_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'phone_digits'], 'waiver_profiles_lookup_idx');
            $table->index(['company_id', 'last_name'], 'waiver_profiles_name_idx');
        });

        Schema::create('waiver_profile_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_profile_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->date('date_of_birth')->nullable();
            $table->string('relationship', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['waiver_profile_id', 'is_active'], 'waiver_profile_dependents_idx');
        });

        Schema::table('waivers', function (Blueprint $table) {
            $table->foreignId('waiver_profile_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::table('waiver_minors', function (Blueprint $table) {
            $table->foreignId('waiver_profile_dependent_id')->nullable()->after('waiver_id')
                ->constrained('waiver_profile_dependents')->nullOnDelete();
            $table->boolean('was_new_this_visit')->default(false)->after('relationship');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_minors', function (Blueprint $table) {
            $table->dropForeign(['waiver_profile_dependent_id']);
            $table->dropColumn(['waiver_profile_dependent_id', 'was_new_this_visit']);
        });

        Schema::table('waivers', function (Blueprint $table) {
            $table->dropForeign(['waiver_profile_id']);
            $table->dropColumn('waiver_profile_id');
        });

        Schema::dropIfExists('waiver_profile_dependents');
        Schema::dropIfExists('waiver_profiles');
    }
};
