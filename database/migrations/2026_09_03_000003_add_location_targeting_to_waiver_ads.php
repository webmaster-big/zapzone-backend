<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiver_template_ads', function (Blueprint $table) {
            $table->json('location_ids')->nullable()->after('waiver_template_id');
        });

        DB::table('waiver_template_ads')
            ->whereNotNull('location_id')
            ->orderBy('id')
            ->each(function ($ad) {
                DB::table('waiver_template_ads')
                    ->where('id', $ad->id)
                    ->update(['location_ids' => json_encode([(int) $ad->location_id])]);
            });

        Schema::table('waiver_template_ads', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_template_ads', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('waiver_template_id')->constrained()->cascadeOnDelete();
        });

        DB::table('waiver_template_ads')
            ->whereNotNull('location_ids')
            ->orderBy('id')
            ->each(function ($ad) {
                $ids = json_decode((string) $ad->location_ids, true);
                if (is_array($ids) && count($ids) === 1) {
                    DB::table('waiver_template_ads')->where('id', $ad->id)->update(['location_id' => (int) $ids[0]]);
                }
            });

        Schema::table('waiver_template_ads', function (Blueprint $table) {
            $table->dropColumn('location_ids');
        });
    }
};
