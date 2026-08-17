<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COORDINATES = [
        ['city' => 'Brighton',         'zip' => '48114', 'lat' => 42.5695675, 'lng' => -83.8147104, 'precision' => 'address'],
        ['city' => 'Canton',           'zip' => '48187', 'lat' => 42.3226513, 'lng' => -83.4551970, 'precision' => 'address'],
        ['city' => 'Farmington',       'zip' => '48336', 'lat' => 42.4566940, 'lng' => -83.3569900, 'precision' => 'address'],
        ['city' => 'Lansing',          'zip' => '48917', 'lat' => 42.7411910, 'lng' => -84.6253670, 'precision' => 'address'],
        ['city' => 'Portage',          'zip' => '49024', 'lat' => 42.2188210, 'lng' => -85.5895320, 'precision' => 'address'],
        ['city' => 'Sterling Heights', 'zip' => '48314', 'lat' => 42.6152390, 'lng' => -83.0317760, 'precision' => 'address'],
        ['city' => 'Taylor',           'zip' => '48180', 'lat' => 42.2359630, 'lng' => -83.2685540, 'precision' => 'address'],
        ['city' => 'Warren',           'zip' => '48092', 'lat' => 42.5224940, 'lng' => -83.0863480, 'precision' => 'street'],
        ['city' => 'Waterford',        'zip' => '48327', 'lat' => 42.6602220, 'lng' => -83.4312320, 'precision' => 'address'],
        ['city' => 'Ypsilanti',        'zip' => '48197', 'lat' => 42.2293330, 'lng' => -83.6799090, 'precision' => 'address'],
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('locations', 'latitude')) {
            return;
        }

        foreach (self::COORDINATES as $row) {
            DB::table('locations')
                ->whereNull('latitude')
                ->where('city', $row['city'])
                ->where('zip_code', $row['zip'])
                ->update([
                    'latitude' => $row['lat'],
                    'longitude' => $row['lng'],
                    'geocode_precision' => $row['precision'],
                    'geocoded_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('locations', 'latitude')) {
            return;
        }

        $cities = array_column(self::COORDINATES, 'city');

        DB::table('locations')
            ->whereIn('city', $cities)
            ->update([
                'latitude' => null,
                'longitude' => null,
                'geocode_precision' => null,
                'geocoded_at' => null,
            ]);
    }
};
