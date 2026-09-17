<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // who approved this booking being saved on top of a conflict, and what the conflict was
            $table->unsignedBigInteger('overlap_override_by')->nullable()->after('status');
            $table->timestamp('overlap_override_at')->nullable()->after('overlap_override_by');
            $table->string('overlap_override_reason', 500)->nullable()->after('overlap_override_at');

            $table->index('overlap_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['overlap_override_by']);
            $table->dropColumn(['overlap_override_by', 'overlap_override_at', 'overlap_override_reason']);
        });
    }
};
