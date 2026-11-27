<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('packages') && ! Schema::hasColumn('packages', 'party_invites_enabled')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->boolean('party_invites_enabled')->default(false)->after('invitation_download_link');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('packages') && Schema::hasColumn('packages', 'party_invites_enabled')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('party_invites_enabled');
            });
        }
    }
};
