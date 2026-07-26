<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('latest_version');
            $table->string('minimum_version');
            $table->boolean('force_update')->default(false);
            $table->text('apk_url')->nullable();
            $table->text('update_message')->nullable();
            $table->json('release_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_versions');
    }
};
