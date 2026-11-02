<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ID = 14;
    private const NAME = 'Test';
    private const SLUG = 'test';

    public function up(): void
    {
        $this->testLocation(true)?->update(['is_active' => false]);
    }

    public function down(): void
    {
        $this->testLocation(false)?->update(['is_active' => true]);
    }

    private function testLocation(bool $active): ?Location
    {
        if (!Schema::hasTable('locations')) {
            return null;
        }

        return Location::query()
            ->whereKey(self::ID)
            ->where('name', self::NAME)
            ->where('slug', self::SLUG)
            ->where('is_active', $active)
            ->first();
    }
};
