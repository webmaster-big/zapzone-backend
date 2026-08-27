<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Support\DataUriImage;
use Illuminate\Console\Command;

class ExternalizePackageImages extends Command
{
    protected $signature = 'packages:externalize-images
        {--dry-run : List the packages holding inline image data without writing anything}';

    protected $description = 'Move base64 image data stored inside packages.image out to files under storage/app/public/images/packages';

    public function handle(): int
    {
        $ids = Package::withTrashed()
            ->where('image', 'like', '%data:image%')
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No packages hold inline image data.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d package(s) hold inline image data.', $ids->count()));
        $failures = 0;

        foreach ($ids as $id) {
            $package = Package::withTrashed()->find($id);
            if (!$package) {
                continue;
            }

            $inlineMb = strlen((string) $package->getRawOriginal('image')) / 1048576;

            if ($this->option('dry-run')) {
                $this->line(sprintf('  #%d %s (location %s) — %.1f MB inline', $package->id, $package->name, $package->location_id, $inlineMb));
                unset($package);
                continue;
            }

            try {
                $package->image = DataUriImage::externalize($package->image, 'images/packages');
                $package->save();
                $this->info(sprintf('  #%d %s — %.1f MB → %s', $package->id, $package->name, $inlineMb, json_encode($package->image)));
            } catch (\Throwable $e) {
                $failures++;
                $this->error(sprintf('  #%d %s — %s', $package->id, $package->name, $e->getMessage()));
            }

            unset($package);
        }

        if ($failures > 0) {
            $this->error("{$failures} package(s) could not be converted.");

            return self::FAILURE;
        }

        $this->info('Done. Package caches were flushed by the model events.');

        return self::SUCCESS;
    }
}
