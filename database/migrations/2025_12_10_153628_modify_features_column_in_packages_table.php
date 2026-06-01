<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("UPDATE packages SET features = '[\"' || REPLACE(REPLACE(features, '\"', '\\\"'), ',', '\",\"') || '\"]' WHERE features IS NOT NULL AND features != ''");
        } else {
            DB::statement("UPDATE packages SET features = CONCAT('[\"', REPLACE(REPLACE(features, '\"', '\\\\\"'), ',', '\",\"'), '\"]') WHERE features IS NOT NULL AND features != ''");
        }

        DB::statement("UPDATE packages SET features = NULL WHERE features = ''");

        Schema::table('packages', function (Blueprint $table) {
            $table->json('features')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->text('features')->nullable()->change();
        });

        $packages = DB::table('packages')->whereNotNull('features')->get();
        foreach ($packages as $package) {
            $featuresArray = json_decode($package->features, true);
            if (is_array($featuresArray)) {
                $featuresString = implode(',', $featuresArray);
                DB::table('packages')->where('id', $package->id)->update(['features' => $featuresString]);
            }
        }
    }
};
