<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['bookings', 'payments', 'attraction_purchases', 'event_purchases', 'package_time_slots'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->relink($table, true);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->relink($table, false);
        }
    }

    private function relink(string $table, bool $keepRows): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'customer_id')) {
            return;
        }

        $existing = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', 'customer_id')
            ->where('REFERENCED_TABLE_NAME', 'customers')
            ->value('CONSTRAINT_NAME');

        if ($existing) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($existing));
        }

        DB::table($table)
            ->whereNotNull('customer_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('customers')->whereColumn('customers.id', "{$table}.customer_id"))
            ->update(['customer_id' => null]);

        Schema::disableForeignKeyConstraints();

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($keepRows) {
                $foreign = $blueprint->foreign('customer_id')->references('id')->on('customers');
                $keepRows ? $foreign->nullOnDelete() : $foreign->cascadeOnDelete();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
