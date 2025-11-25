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
        // First, update existing data to convert strings to JSON arrays
        DB::statement("UPDATE attractions SET image = JSON_ARRAY(image) WHERE image IS NOT NULL AND image != ''");
        
        // Update empty strings to NULL
        DB::statement("UPDATE attractions SET image = NULL WHERE image = ''");
        
        Schema::table('attractions', function (Blueprint $table) {
            // Change image column from string to json
            $table->json('image')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, convert JSON arrays back to single strings (take first element)
        DB::statement("UPDATE attractions SET image = JSON_UNQUOTE(JSON_EXTRACT(image, '$[0]')) WHERE image IS NOT NULL");
        
        Schema::table('attractions', function (Blueprint $table) {
            // Revert image column back to string
            $table->string('image')->nullable()->change();
        });
    }
};
