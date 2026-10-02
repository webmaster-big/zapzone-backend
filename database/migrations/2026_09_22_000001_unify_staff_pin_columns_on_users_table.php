<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'override_pin') && ! Schema::hasColumn('users', 'pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('override_pin', 'pin_hash');
            });
        }

        if (Schema::hasColumn('users', 'override_pin_set_at') && ! Schema::hasColumn('users', 'pin_set_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('override_pin_set_at', 'pin_set_at');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pin_hash')) {
                $table->string('pin_hash')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'pin_set_at')) {
                $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
            }

            if (! Schema::hasColumn('users', 'pin_lookup')) {
                $table->string('pin_lookup', 64)->nullable()->after('pin_set_at');
            }

            if (! Schema::hasColumn('users', 'pin_lookup_v')) {
                $table->unsignedTinyInteger('pin_lookup_v')->default(1)->after('pin_lookup');
            }

            if (! Schema::hasColumn('users', 'pin_set_by')) {
                $table->unsignedBigInteger('pin_set_by')->nullable()->after('pin_lookup_v');
            }

            if (! Schema::hasColumn('users', 'pin_failed_attempts')) {
                $table->unsignedSmallInteger('pin_failed_attempts')->default(0)->after('pin_set_by');
            }

            if (! Schema::hasColumn('users', 'pin_locked_until')) {
                $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_attempts');
            }
        });

        if (! $this->hasIndex('users', 'users_pin_lookup_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('pin_lookup', 'users_pin_lookup_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('users', 'users_pin_lookup_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_pin_lookup_unique');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['pin_lookup', 'pin_lookup_v', 'pin_set_by', 'pin_failed_attempts', 'pin_locked_until'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('users', 'pin_hash') && ! Schema::hasColumn('users', 'override_pin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('pin_hash', 'override_pin');
            });
        }

        if (Schema::hasColumn('users', 'pin_set_at') && ! Schema::hasColumn('users', 'override_pin_set_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('pin_set_at', 'override_pin_set_at');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            return collect(Schema::getIndexes($table))
                ->contains(fn (array $existing) => ($existing['name'] ?? null) === $index);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
