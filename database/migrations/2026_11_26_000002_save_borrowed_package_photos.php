<?php

use App\Models\Package;
use App\Support\DataUriImage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages') || ! Schema::hasColumn('packages', 'image')) {
            return;
        }

        $listed = Package::query()
            ->where('is_active', true)
            ->availableOnline()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $saved = [];
        $skipped = [];
        $sources = [];

        foreach ($listed as $package) {
            if (! isset($sources[$package->name]) && ! empty($package->image) && $this->firstReference($package->image) !== null) {
                $sources[$package->name] = $package;
            }
        }

        $listedIds = $listed->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unlistedSources = [];

        $unlisted = Package::query()
            ->when($listedIds !== [], fn ($query) => $query->whereNotIn('id', $listedIds))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        foreach ($unlisted as $package) {
            if (! isset($sources[$package->name]) && ! isset($unlistedSources[$package->name]) && $this->firstReference($package->image) !== null) {
                $unlistedSources[$package->name] = $package;
            }
        }

        $names = array_merge(array_keys($sources), array_keys($unlistedSources));

        $everyPackage = $names === [] ? collect() : Package::withTrashed()
            ->whereIn('name', $names)
            ->orderBy('id')
            ->get()
            ->filter(fn (Package $package) => isset($sources[$package->name]) || isset($unlistedSources[$package->name]))
            ->groupBy('name');

        foreach ($everyPackage as $name => $group) {
            $source = $sources[$name] ?? $unlistedSources[$name];
            $onlyUnlistedTargets = ! isset($sources[$name]);
            $reference = $this->firstReference($source->image);

            foreach ($group as $package) {
                if ($this->firstReference($package->image) !== null) {
                    continue;
                }

                if ($onlyUnlistedTargets && in_array((int) $package->id, $listedIds, true)) {
                    continue;
                }

                if (DataUriImage::isDataUri($reference)) {
                    $skipped[] = $package->id;

                    continue;
                }

                $package->update(['image' => [$reference]]);
                $saved[] = ['package_id' => $package->id, 'location_id' => $package->location_id, 'name' => $name, 'image' => $reference, 'from_package_id' => $source->id];
            }
        }

        Log::info('Saved the borrowed catalog photo onto packages that had none of their own', [
            'saved' => $saved,
            'skipped_inline_images' => $skipped,
        ]);
    }

    public function down(): void
    {
    }

    private function firstReference(mixed $image): ?string
    {
        $list = is_array($image) ? $image : [$image];

        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                return $item;
            }
        }

        return null;
    }
};
