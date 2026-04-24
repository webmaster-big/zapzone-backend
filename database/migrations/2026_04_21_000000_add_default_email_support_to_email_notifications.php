<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_notifications', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->string('default_key')->nullable()->after('is_default');
            $table->text('description')->nullable()->after('name');
            $table->longText('default_body')->nullable()->after('body');
            $table->string('default_subject')->nullable()->after('subject');

            $table->index('is_default');
            $table->unique(['company_id', 'default_key'], 'email_notifications_company_default_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('email_notifications', function (Blueprint $table) {
            $table->dropUnique('email_notifications_company_default_key_unique');
            $table->dropIndex(['is_default']);
            $table->dropColumn(['is_default', 'default_key', 'description', 'default_body', 'default_subject']);
        });
    }
};
