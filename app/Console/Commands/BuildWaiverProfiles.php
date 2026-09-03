<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Waiver;
use App\Models\WaiverProfile;
use App\Models\WaiverProfileDependent;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildWaiverProfiles extends Command
{
    protected $signature = 'waivers:build-profiles
        {--company= : Only backfill this company id}
        {--dry-run : Report what would change without writing anything}
        {--chunk=500 : Waivers read per query}';

    protected $description = 'Turn existing completed waivers into returning-customer profiles, linking each waiver as one submission on the person record';

    protected int $profilesCreated = 0;
    protected int $waiversLinked = 0;
    protected int $dependentsSeeded = 0;
    protected int $conflictsFlagged = 0;
    protected int $waiversSkipped = 0;

    public function handle(): int
    {
        $chunk = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $companyIds = $this->companyIds();
        if ($companyIds->isEmpty()) {
            $this->error('No companies with completed waivers were found.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        foreach ($companyIds as $companyId) {
            $this->line(sprintf('Company #%d', $companyId));

            $this->processCompany($companyId, $chunk, $dryRun);
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Profiles created', $this->profilesCreated],
            ['Waivers linked', $this->waiversLinked],
            ['Dependents seeded', $this->dependentsSeeded],
            ['Conflicts flagged', $this->conflictsFlagged],
            ['Waivers skipped (unusable phone)', $this->waiversSkipped],
        ]);

        return self::SUCCESS;
    }

    protected function companyIds(): Collection
    {
        if ($id = $this->option('company')) {
            $companyId = (int) $id;
            if (!Company::whereKey($companyId)->exists()) {
                $this->error("Company #{$companyId} does not exist.");

                return collect();
            }

            return collect([$companyId]);
        }

        return Waiver::where('status', Waiver::STATUS_COMPLETED)
            ->whereNotNull('company_id')
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(fn ($v) => (int) $v);
    }

    protected function processCompany(int $companyId, int $chunk, bool $dryRun): void
    {
        $groups = $this->groupWaiverIds($companyId, $chunk);

        if ($groups->isEmpty()) {
            $this->line('  no completed waivers with a usable phone');

            return;
        }

        $this->line(sprintf('  %d phone group(s)', $groups->count()));

        foreach ($groups->chunk($chunk) as $slice) {
            foreach ($slice as $digits => $ids) {
                if ($dryRun) {
                    $this->processGroup($companyId, (string) $digits, $ids, true);
                    continue;
                }

                DB::transaction(fn () => $this->processGroup($companyId, (string) $digits, $ids, false));
            }
        }
    }

    protected function groupWaiverIds(int $companyId, int $chunk): Collection
    {
        $groups = [];

        Waiver::where('company_id', $companyId)
            ->where('status', Waiver::STATUS_COMPLETED)
            ->whereNotNull('adult_phone')
            ->orderBy('id')
            ->select(['id', 'adult_phone'])
            ->chunkById($chunk, function (Collection $waivers) use (&$groups) {
                foreach ($waivers as $waiver) {
                    $digits = WaiverProfile::digitsFor($waiver->adult_phone);
                    if (!$digits) {
                        $this->waiversSkipped++;
                        continue;
                    }
                    $groups[$digits][] = (int) $waiver->id;
                }
            });

        $this->waiversSkipped += Waiver::where('company_id', $companyId)
            ->where('status', Waiver::STATUS_COMPLETED)
            ->whereNull('adult_phone')
            ->count();

        return collect($groups);
    }

    protected function processGroup(int $companyId, string $digits, array $ids, bool $dryRun): void
    {
        $waivers = Waiver::with('minors')
            ->whereIn('id', $ids)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        if ($waivers->isEmpty()) {
            return;
        }

        $latest = $waivers->last();
        $conflicted = $this->hasDifferentNames($waivers);

        $existing = WaiverProfile::where('company_id', $companyId)
            ->where('phone_digits', $digits)
            ->orderBy('id')
            ->get();

        $profile = $existing->first();
        $needsReview = $conflicted || $existing->count() > 1;

        if ($dryRun) {
            if (!$profile) {
                $this->profilesCreated++;
            }
            if ($needsReview && !($profile?->needs_staff_review)) {
                $this->conflictsFlagged++;
            }
            $this->waiversLinked += $waivers->whereNull('waiver_profile_id')->count();
            $this->dependentsSeeded += $this->countNewDependents($waivers, $profile);

            return;
        }

        if (!$profile) {
            $profile = WaiverProfile::create([
                'company_id' => $companyId,
                'last_location_id' => $latest->location_id,
                'phone_digits' => $digits,
                'phone_e164' => SmsService::toE164($latest->adult_phone),
                'phone_raw' => $latest->adult_phone,
                'first_name' => (string) $latest->adult_first_name,
                'last_name' => (string) $latest->adult_last_name,
                'email' => $latest->adult_email,
                'date_of_birth' => $latest->adult_dob,
                'needs_staff_review' => $needsReview,
            ]);
            $this->profilesCreated++;
            if ($needsReview) {
                $this->conflictsFlagged++;
            }
        } elseif ($needsReview && !$profile->needs_staff_review) {
            $profile->update(['needs_staff_review' => true]);
            $this->conflictsFlagged++;
        }

        foreach ($waivers as $waiver) {
            if (!$waiver->waiver_profile_id) {
                $waiver->forceFill(['waiver_profile_id' => $profile->id])->saveQuietly();
                $this->waiversLinked++;
            }
            $this->seedDependents($profile, $waiver);
        }

        $profile->update([
            'last_location_id' => $latest->location_id ?: $profile->last_location_id,
            'phone_e164' => SmsService::toE164($latest->adult_phone) ?: $profile->phone_e164,
            'phone_raw' => $latest->adult_phone ?: $profile->phone_raw,
            'last_waiver_at' => $latest->submitted_at ?: $profile->last_waiver_at,
            'submissions_count' => $profile->waivers()->where('status', Waiver::STATUS_COMPLETED)->count(),
        ]);
    }

    protected function hasDifferentNames(Collection $waivers): bool
    {
        return $waivers
            ->map(fn (Waiver $w) => $this->normalise($w->adult_first_name) . '|' . $this->normalise($w->adult_last_name))
            ->unique()
            ->count() > 1;
    }

    protected function seedDependents(WaiverProfile $profile, Waiver $waiver): void
    {
        $profile->loadMissing('dependents');

        foreach ($waiver->minors as $minor) {
            if ($minor->waiver_profile_dependent_id) {
                continue;
            }
            if ($this->normalise($minor->first_name) === '' || $this->normalise($minor->last_name) === '') {
                continue;
            }

            $dob = $minor->date_of_birth?->toDateString();
            $existing = $profile->dependents->first(fn (WaiverProfileDependent $d) => $d->matches($minor->first_name, $minor->last_name, $dob));

            $dependent = $existing;
            if (!$dependent) {
                $dependent = $profile->dependents()->create([
                    'first_name' => $minor->first_name,
                    'last_name' => $minor->last_name,
                    'date_of_birth' => $dob,
                    'relationship' => $minor->relationship,
                    'is_active' => true,
                ]);
                $this->dependentsSeeded++;
                $profile->setRelation('dependents', $profile->dependents->push($dependent)->unique('id'));
            }

            $minor->forceFill(['waiver_profile_dependent_id' => $dependent->id])->saveQuietly();
        }
    }

    protected function countNewDependents(Collection $waivers, ?WaiverProfile $profile): int
    {
        $seen = $profile
            ? $profile->dependents->map(fn (WaiverProfileDependent $d) => $this->dependentKey($d->first_name, $d->last_name, $d->date_of_birth?->toDateString()))->all()
            : [];
        $new = 0;

        foreach ($waivers as $waiver) {
            foreach ($waiver->minors as $minor) {
                if ($minor->waiver_profile_dependent_id) {
                    continue;
                }
                if ($this->normalise($minor->first_name) === '' || $this->normalise($minor->last_name) === '') {
                    continue;
                }
                $key = $this->dependentKey($minor->first_name, $minor->last_name, $minor->date_of_birth?->toDateString());
                if (in_array($key, $seen, true)) {
                    continue;
                }
                $seen[] = $key;
                $new++;
            }
        }

        return $new;
    }

    protected function dependentKey(?string $first, ?string $last, ?string $dob): string
    {
        return $this->normalise($first) . '|' . $this->normalise($last) . '|' . ($dob ?: '');
    }

    protected function normalise(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
