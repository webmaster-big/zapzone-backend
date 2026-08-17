<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Support\CacheGroups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodeLocations extends Command
{
    protected $signature = 'locations:geocode
        {--force : Re-geocode locations that already have coordinates}
        {--dry-run : Show what would be stored without writing anything}
        {--location= : Only geocode this location id}';

    protected $description = 'Resolve each location address to coordinates so the storefront map can plot it';

    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const PRECISION_ADDRESS = 'address';
    private const PRECISION_STREET = 'street';
    private const PRECISION_CITY = 'city';

    private const PRECISION_LABELS = [
        self::PRECISION_ADDRESS => 'exact building',
        self::PRECISION_STREET => 'right road, building not mapped',
        self::PRECISION_CITY => 'city centre only',
    ];

    private const CONTINENTAL_US = [
        'min_lat' => 24.0,
        'max_lat' => 49.5,
        'min_lon' => -125.0,
        'max_lon' => -66.0,
    ];

    public function handle(): int
    {
        $query = Location::query()->orderBy('id');

        if ($id = $this->option('location')) {
            $query->where('id', (int) $id);
        }

        if (!$this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }

        $locations = $query->get();

        if ($locations->isEmpty()) {
            $this->info('Nothing to geocode. Every location already has coordinates (use --force to redo them).');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->line($dryRun
            ? "Resolving {$locations->count()} location(s) — dry run, nothing will be saved."
            : "Resolving {$locations->count()} location(s).");
        $this->line('One request per second, as the geocoding service asks.');
        $this->line('');

        $stored = 0;
        $needsReview = [];

        foreach ($locations as $index => $location) {
            if ($index > 0) {
                usleep(1_100_000);
            }

            $result = $this->resolve($location);

            if ($result === null) {
                $this->line(sprintf('  <fg=red>✗</> %-34s could not be resolved', $this->label($location)));
                $needsReview[] = $location;
                continue;
            }

            [$latitude, $longitude, $precision, $display] = $result;

            $marker = $precision === self::PRECISION_ADDRESS ? '<fg=green>✓</>' : '<fg=yellow>~</>';
            $this->line(sprintf(
                '  %s %-34s %10.6f, %11.6f  (%s)',
                $marker,
                $this->label($location),
                $latitude,
                $longitude,
                self::PRECISION_LABELS[$precision] ?? $precision
            ));
            $this->line(sprintf('    %s', $display));

            if ($precision !== self::PRECISION_ADDRESS) {
                $needsReview[] = $location;
            }

            if ($dryRun) {
                continue;
            }

            $location->forceFill([
                'latitude' => round($latitude, 7),
                'longitude' => round($longitude, 7),
                'geocode_precision' => $precision,
                'geocoded_at' => now(),
            ])->save();

            $stored++;
        }

        if (!$dryRun && $stored > 0) {
            CacheGroups::flush([CacheGroups::LOCATIONS]);
        }

        $this->line('');
        $this->info($dryRun
            ? "Dry run finished. {$locations->count()} checked, nothing saved."
            : "Saved coordinates for {$stored} of {$locations->count()} location(s).");

        if ($needsReview !== []) {
            $this->line('');
            $this->warn('These pins are not street-accurate and are worth a look:');
            foreach ($needsReview as $location) {
                $this->line(sprintf('  - %s (%s)', $this->label($location), $location->address ?: 'no address on record'));
            }
            $this->line('');
            $this->line('Fix the address and run again, or set latitude and longitude by hand.');
        }

        return self::SUCCESS;
    }

    private function label(Location $location): string
    {
        return \Illuminate\Support\Str::limit($location->name, 32, '');
    }

    private function resolve(Location $location): ?array
    {
        foreach ($this->attempts($location) as [$precision, $params]) {
            $payload = $this->request($params);

            if ($payload === null || $payload === []) {
                continue;
            }

            $hit = $payload[0];
            $latitude = isset($hit['lat']) ? (float) $hit['lat'] : null;
            $longitude = isset($hit['lon']) ? (float) $hit['lon'] : null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            if (!$this->withinContinentalUs($latitude, $longitude)) {
                continue;
            }

            $display = (string) ($hit['display_name'] ?? '');

            if (!$this->looksLikeTheRightPlace($display, $location)) {
                continue;
            }

            return [$latitude, $longitude, $this->refine($precision, $display, $location), $display];
        }

        return null;
    }

    private function refine(string $precision, string $display, Location $location): string
    {
        if ($precision !== self::PRECISION_ADDRESS) {
            return $precision;
        }

        if (!preg_match('/^\s*(\d+)/', (string) $location->address, $matches)) {
            return self::PRECISION_STREET;
        }

        return str_contains($display, $matches[1]) ? self::PRECISION_ADDRESS : self::PRECISION_STREET;
    }

    private function attempts(Location $location): array
    {
        $city = trim((string) $location->city);
        $state = trim((string) $location->state);
        $zip = trim((string) $location->zip_code);
        $street = trim((string) $location->address);

        $attempts = [];

        if ($street !== '') {
            $attempts[] = [self::PRECISION_ADDRESS, array_filter([
                'street' => $street,
                'city' => $city,
                'state' => $state,
                'postalcode' => $zip,
            ])];

            $attempts[] = [self::PRECISION_ADDRESS, [
                'q' => implode(', ', array_filter([$street, $city, $state, $zip])),
            ]];

            if ($zip !== '') {
                $attempts[] = [self::PRECISION_ADDRESS, array_filter([
                    'street' => $street,
                    'state' => $state,
                    'postalcode' => $zip,
                ])];
            }
        }

        if ($city !== '') {
            $attempts[] = [self::PRECISION_CITY, array_filter([
                'city' => $city,
                'state' => $state,
                'postalcode' => $zip,
            ])];

            $attempts[] = [self::PRECISION_CITY, array_filter([
                'city' => $city,
                'state' => $state,
            ])];
        }

        return $attempts;
    }

    private function request(array $params): ?array
    {
        try {
            $response = Http::withHeaders([
                    'User-Agent' => $this->userAgent(),
                    'Accept' => 'application/json',
                ])
                ->timeout(25)
                ->get(self::ENDPOINT, array_merge($params, [
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'us',
                    'addressdetails' => 0,
                ]));

            if (!$response->successful()) {
                return null;
            }

            $body = $response->json();

            return is_array($body) ? $body : null;
        } catch (\Throwable $e) {
            Log::warning('Location geocoding request failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function userAgent(): string
    {
        $app = config('app.name', 'ZapZone');
        $url = config('app.url', 'https://zap-zone.com');

        return "{$app} location geocoder ({$url})";
    }

    private function withinContinentalUs(float $latitude, float $longitude): bool
    {
        return $latitude >= self::CONTINENTAL_US['min_lat']
            && $latitude <= self::CONTINENTAL_US['max_lat']
            && $longitude >= self::CONTINENTAL_US['min_lon']
            && $longitude <= self::CONTINENTAL_US['max_lon'];
    }

    private function looksLikeTheRightPlace(string $display, Location $location): bool
    {
        $haystack = mb_strtolower($display);
        $city = trim((string) $location->city);
        $zip = trim((string) $location->zip_code);

        if ($city === '' && $zip === '') {
            return true;
        }

        if ($zip !== '' && str_contains($haystack, mb_strtolower($zip))) {
            return true;
        }

        return $city !== '' && str_contains($haystack, mb_strtolower($city));
    }
}
