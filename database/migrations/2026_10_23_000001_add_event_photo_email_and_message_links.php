<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('photo_message_templates') && !Schema::hasColumn('photo_message_templates', 'attach_photos')) {
            Schema::table('photo_message_templates', function (Blueprint $table) {
                $table->boolean('attach_photos')->default(false)->after('sms_body');
            });
        }

        if (!Schema::hasTable('message_links')) {
            Schema::create('message_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('key', 40);
                $table->string('label', 80);
                $table->string('default_url', 500)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasIndex('message_links', 'message_links_company_key_unique')) {
            Schema::table('message_links', fn (Blueprint $table) => $table->unique(['company_id', 'key'], 'message_links_company_key_unique'));
        }

        if (!$this->hasForeignKeyOn('message_links', 'company_id')) {
            Schema::table('message_links', fn (Blueprint $table) => $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete());
        }

        if (!Schema::hasTable('message_link_urls')) {
            Schema::create('message_link_urls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('message_link_id');
                $table->unsignedBigInteger('location_id');
                $table->string('url', 500);
                $table->timestamps();
            });
        }

        if (!Schema::hasIndex('message_link_urls', 'message_link_urls_link_location_unique')) {
            Schema::table('message_link_urls', fn (Blueprint $table) => $table->unique(['message_link_id', 'location_id'], 'message_link_urls_link_location_unique'));
        }

        if (!Schema::hasIndex('message_link_urls', 'message_link_urls_location_idx')) {
            Schema::table('message_link_urls', fn (Blueprint $table) => $table->index('location_id', 'message_link_urls_location_idx'));
        }

        foreach (['message_link_id' => 'message_links', 'location_id' => 'locations'] as $column => $references) {
            if (!$this->hasForeignKeyOn('message_link_urls', $column)) {
                Schema::table('message_link_urls', fn (Blueprint $table) => $table->foreign($column)->references('id')->on($references)->cascadeOnDelete());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_link_urls');
        Schema::dropIfExists('message_links');

        if (Schema::hasTable('photo_message_templates') && Schema::hasColumn('photo_message_templates', 'attach_photos')) {
            Schema::table('photo_message_templates', fn (Blueprint $table) => $table->dropColumn('attach_photos'));
        }
    }

    protected function hasForeignKeyOn(string $table, string $column): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
};
