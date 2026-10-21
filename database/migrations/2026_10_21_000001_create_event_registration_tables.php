<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['registration_entries', 'registration_contacts', 'registration_events'];

    private const REQUIRED_UNIQUE_INDEXES = [
        'registration_events' => 'registration_events_public_code_unique',
        'registration_contacts' => 'registration_contacts_identity_unique',
        'registration_entries' => 'registration_entries_submission_unique',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('registration_events')) {
            Schema::create('registration_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('public_code', 40)->charset('ascii')->collation('ascii_bin');
                $table->date('event_date');
                $table->text('description')->nullable();
                $table->string('confirmation_message', 500)->nullable();
                $table->string('header_image_path', 255)->nullable();
                $table->unsignedSmallInteger('header_image_width')->nullable();
                $table->unsignedSmallInteger('header_image_height')->nullable();
                $table->foreignId('previous_edition_id')->nullable()->constrained('registration_events')->nullOnDelete();
                $table->boolean('is_open')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('public_code', 'registration_events_public_code_unique');
                $table->index(['company_id', 'location_id', 'event_date'], 'registration_events_scope_index');
            });
        }

        if (! Schema::hasTable('registration_contacts')) {
            Schema::create('registration_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('email_key', 190)->collation('utf8mb4_bin');
                $table->string('phone_key', 20)->charset('ascii')->collation('ascii_bin');
                $table->timestamps();

                $table->unique(['company_id', 'email_key', 'phone_key'], 'registration_contacts_identity_unique');
            });
        }

        if (! Schema::hasTable('registration_entries')) {
            Schema::create('registration_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('registration_event_id')->constrained('registration_events')->cascadeOnDelete();
                $table->foreignId('registration_contact_id')->constrained('registration_contacts')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('name_key', 120);
                $table->string('email', 190);
                $table->string('phone', 40);
                $table->string('submission_key', 64)->charset('ascii')->collation('ascii_bin');
                $table->timestamp('hidden_at')->nullable();
                $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['registration_event_id', 'submission_key'], 'registration_entries_submission_unique');
                $table->index(['registration_event_id', 'registration_contact_id'], 'registration_entries_event_contact_index');
                $table->index(['registration_contact_id', 'name_key'], 'registration_entries_contact_name_index');
            });
        }

        foreach (self::REQUIRED_UNIQUE_INDEXES as $table => $index) {
            if (! Schema::hasIndex($table, $index, 'unique')) {
                throw new RuntimeException("{$table} exists without {$index}. Drop the empty registration tables and run migrate again.");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('The event registration tables hold registrations. Export or remove them before rolling back.');
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
