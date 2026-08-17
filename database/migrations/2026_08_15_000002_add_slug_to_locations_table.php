<?php

use App\Support\CacheGroups;
use App\Support\LocationSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('locations', 'slug')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('slug', 120)->nullable()->after('name');
            });
        }

        $this->backfillSlugs();
        $this->addUniqueIndex();

        CacheGroups::flush([CacheGroups::LOCATIONS]);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('locations', 'slug')) {
            return;
        }

        try {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropUnique('locations_slug_unique');
            });
        } catch (\Throwable $e) {
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }

    private function backfillSlugs(): void
    {
        $taken = [];

        foreach (DB::table('locations')->orderBy('id')->get(['id', 'name', 'city', 'slug']) as $row) {
            $slug = filled($row->slug)
                ? $row->slug
                : LocationSlug::unique(LocationSlug::preferredSource($row->city, $row->name), $taken);

            $taken[] = $slug;

            if ($row->slug !== $slug) {
                DB::table('locations')->where('id', $row->id)->update(['slug' => $slug]);
            }
        }
    }

    private function addUniqueIndex(): void
    {
        try {
            if (Schema::hasIndex('locations', ['slug'])) {
                return;
            }
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('locations', function (Blueprint $table) {
                $table->unique('slug');
            });
        } catch (\Throwable $e) {
        }
    }
};
