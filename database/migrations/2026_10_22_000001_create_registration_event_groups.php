<?php

use App\Support\RegistrationFormGrouping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registration_events')) {
            return;
        }

        if (! Schema::hasTable('registration_event_groups')) {
            Schema::create('registration_event_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->date('event_date');
                $table->text('description')->nullable();
                $table->string('confirmation_message', 500)->nullable();
                $table->string('header_image_path')->nullable();
                $table->unsignedSmallInteger('header_image_width')->nullable();
                $table->unsignedSmallInteger('header_image_height')->nullable();
                $table->foreignId('previous_group_id')->nullable()->constrained('registration_event_groups')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['company_id', 'event_date'], 'registration_event_groups_scope_index');
            });
        }

        if (! Schema::hasColumn('registration_events', 'group_id')) {
            Schema::table('registration_events', function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->after('company_id')->constrained('registration_event_groups')->nullOnDelete();
            });
        }

        DB::transaction(fn () => RegistrationFormGrouping::groupUngroupedForms());
    }

    public function down(): void
    {
        if (Schema::hasTable('registration_events') && Schema::hasColumn('registration_events', 'group_id')) {
            Schema::table('registration_events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('group_id');
            });
        }

        Schema::dropIfExists('registration_event_groups');
    }
};
