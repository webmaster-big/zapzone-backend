<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['attraction_purchases', 'event_purchases'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'ticket_order_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('ticket_order_id')->nullable()->constrained('ticket_orders')->nullOnDelete();
                $table->unsignedInteger('line_position')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['attraction_purchases', 'event_purchases'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'ticket_order_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['ticket_order_id']);
                $table->dropColumn(['ticket_order_id', 'line_position']);
            });
        }
    }
};
