<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fee_supports')) {
            return;
        }

        if (! Schema::hasColumn('fee_supports', 'charge_type')) {
            Schema::table('fee_supports', function (Blueprint $table) {
                $table->string('charge_type', 10)->default('fee')->after('fee_name');
            });
        }

        $taxes = DB::table('fee_supports')
            ->where('charge_type', 'fee')
            ->get(['id', 'fee_name'])
            ->filter(fn ($fee) => \App\Models\FeeSupport::nameLooksLikeTax((string) $fee->fee_name))
            ->pluck('id')
            ->all();

        if ($taxes !== []) {
            DB::table('fee_supports')->whereIn('id', $taxes)->update(['charge_type' => 'tax']);
        }

        Log::info('Fee charge types set from their names', ['marked_as_tax' => $taxes]);
    }

    public function down(): void
    {
        if (Schema::hasTable('fee_supports') && Schema::hasColumn('fee_supports', 'charge_type')) {
            Schema::table('fee_supports', function (Blueprint $table) {
                $table->dropColumn('charge_type');
            });
        }
    }
};
