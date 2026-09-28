<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'waivers_escape_room_session_id_foreign';

    public function up(): void
    {
        if (!Schema::hasTable('escape_room_sessions')) {
            return;
        }

        if (!Schema::hasColumn('waivers', 'escape_room_session_id')) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->unsignedBigInteger('escape_room_session_id')->nullable();
            });
        }

        if ($this->foreignKeyExists()) {
            return;
        }

        $mysql = DB::getDriverName() === 'mysql';

        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            Schema::table('waivers', function (Blueprint $table) {
                $table->foreign('escape_room_session_id', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('escape_room_sessions')
                    ->nullOnDelete();
            });
        } finally {
            if ($mysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('waivers', 'escape_room_session_id')) {
            return;
        }

        if ($this->foreignKeyExists()) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        Schema::table('waivers', function (Blueprint $table) {
            $table->dropColumn('escape_room_session_id');
        });
    }

    private function foreignKeyExists(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return collect(Schema::getForeignKeys('waivers'))->contains(fn ($key) => ($key['name'] ?? null) === self::FOREIGN_KEY);
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'waivers')
            ->where('constraint_name', self::FOREIGN_KEY)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
