<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE attractions SET image = JSON_ARRAY(image) WHERE image IS NOT NULL AND image != ''");
        
        DB::statement("UPDATE attractions SET image = NULL WHERE image = ''");
        
        Schema::table('attractions', function (Blueprint $table) {
            $table->json('image')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE attractions SET image = JSON_UNQUOTE(JSON_EXTRACT(image, '$[0]')) WHERE image IS NOT NULL");
        
        Schema::table('attractions', function (Blueprint $table) {
            $table->string('image')->nullable()->change();
        });
    }
};
