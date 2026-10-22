<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('registration_events') || !Schema::hasTable('registration_entries')) {
            return;
        }

        if (Schema::hasTable('photo_sessions')) {
            if (!Schema::hasTable('photo_session_registrations')) {
                Schema::create('photo_session_registrations', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('photo_session_id');
                    $table->unsignedBigInteger('registration_entry_id');
                    $table->timestamps();
                });
            }

            if (!Schema::hasIndex('photo_session_registrations', 'photo_session_registrations_unique')) {
                Schema::table('photo_session_registrations', fn (Blueprint $table) => $table->unique(['photo_session_id', 'registration_entry_id'], 'photo_session_registrations_unique'));
            }

            if (!Schema::hasIndex('photo_session_registrations', 'photo_session_registrations_entry_idx')) {
                Schema::table('photo_session_registrations', fn (Blueprint $table) => $table->index('registration_entry_id', 'photo_session_registrations_entry_idx'));
            }

            foreach (['photo_session_id' => 'photo_sessions', 'registration_entry_id' => 'registration_entries'] as $column => $references) {
                if (!$this->hasForeignKeyOn('photo_session_registrations', $column)) {
                    Schema::table('photo_session_registrations', fn (Blueprint $table) => $table->foreign($column)->references('id')->on($references)->cascadeOnDelete());
                }
            }

            if (!Schema::hasIndex('photo_session_registrations', 'photo_session_registrations_unique', 'unique')) {
                throw new RuntimeException('photo_session_registrations is missing its unique index, so the same guest could be linked to a photo twice.');
            }
        }

        if (Schema::hasTable('photo_deliveries')) {
            if (!Schema::hasColumn('photo_deliveries', 'registration_entry_id')) {
                Schema::table('photo_deliveries', function (Blueprint $table) {
                    $column = $table->unsignedBigInteger('registration_entry_id')->nullable();

                    if (Schema::hasColumn('photo_deliveries', 'waiver_id')) {
                        $column->after('waiver_id');
                    }
                });
            }

            if (!Schema::hasIndex('photo_deliveries', 'photo_deliveries_registration_entry_idx')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->index('registration_entry_id', 'photo_deliveries_registration_entry_idx'));
            }

            if (!$this->hasForeignKeyOn('photo_deliveries', 'registration_entry_id')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->foreign('registration_entry_id')->references('id')->on('registration_entries')->nullOnDelete());
            }
        }

        if (Schema::hasTable('photo_overlays')) {
            if (!Schema::hasColumn('photo_overlays', 'registration_event_id')) {
                Schema::table('photo_overlays', function (Blueprint $table) {
                    $column = $table->unsignedBigInteger('registration_event_id')->nullable();

                    if (Schema::hasColumn('photo_overlays', 'package_id')) {
                        $column->after('package_id');
                    }
                });
            }

            if (!Schema::hasIndex('photo_overlays', 'photo_overlays_registration_event_idx')) {
                Schema::table('photo_overlays', fn (Blueprint $table) => $table->index('registration_event_id', 'photo_overlays_registration_event_idx'));
            }

            if (!$this->hasForeignKeyOn('photo_overlays', 'registration_event_id')) {
                Schema::table('photo_overlays', fn (Blueprint $table) => $table->foreign('registration_event_id')->references('id')->on('registration_events')->cascadeOnDelete());
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('photo_overlays') && Schema::hasColumn('photo_overlays', 'registration_event_id')) {
            DB::table('photo_overlays')->whereNotNull('registration_event_id')->update(['is_enabled' => false]);

            if ($this->hasForeignKeyOn('photo_overlays', 'registration_event_id')) {
                Schema::table('photo_overlays', fn (Blueprint $table) => $table->dropForeign(['registration_event_id']));
            }

            if (Schema::hasIndex('photo_overlays', 'photo_overlays_registration_event_idx')) {
                Schema::table('photo_overlays', fn (Blueprint $table) => $table->dropIndex('photo_overlays_registration_event_idx'));
            }

            Schema::table('photo_overlays', fn (Blueprint $table) => $table->dropColumn('registration_event_id'));
        }

        if (Schema::hasTable('photo_deliveries') && Schema::hasColumn('photo_deliveries', 'registration_entry_id')) {
            if ($this->hasForeignKeyOn('photo_deliveries', 'registration_entry_id')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropForeign(['registration_entry_id']));
            }

            if (Schema::hasIndex('photo_deliveries', 'photo_deliveries_registration_entry_idx')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropIndex('photo_deliveries_registration_entry_idx'));
            }

            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropColumn('registration_entry_id'));
        }

        Schema::dropIfExists('photo_session_registrations');
    }

    protected function hasForeignKeyOn(string $table, string $column): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
};
