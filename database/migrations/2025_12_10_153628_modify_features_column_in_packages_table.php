<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, convert existing comma-separated features to JSON array
        DB::statement("UPDATE packages SET features = CONCAT('[\"', REPLACE(REPLACE(features, '\"', '\\\\\"'), ',', '\",\"'), '\"]') WHERE features IS NOT NULL AND features != ''");

        // Handle empty strings
        DB::statement("UPDATE packages SET features = NULL WHERE features = ''");

        Schema::table('packages', function (Blueprint $table) {
            // Change features from text to json to store array
            $table->json('features')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Revert back to text
            $table->text('features')->nullable()->change();
        });

        // Convert JSON arrays back to comma-separated strings
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
