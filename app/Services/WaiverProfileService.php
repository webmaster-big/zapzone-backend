<?php

namespace App\Services;

use App\Models\Waiver;
use App\Models\WaiverProfile;
use App\Models\WaiverProfileDependent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WaiverProfileService
{
    public const STATUS_FOUND = 'found';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_NEEDS_STAFF = 'needs_staff';

    public function lookup(int $companyId, ?string $phone): array
    {
        $digits = WaiverProfile::digitsFor($phone);
        if (!$digits) {
            return ['status' => self::STATUS_NOT_FOUND];
        }

        $matches = WaiverProfile::with(['activeDependents' => fn ($q) => $q->orderBy('first_name')])
            ->where('company_id', $companyId)
            ->where('phone_digits', $digits)
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            return ['status' => self::STATUS_NOT_FOUND];
        }

        if ($matches->count() > 1) {
            return ['status' => self::STATUS_NEEDS_STAFF];
        }

        $profile = $matches->first();
        if ($profile->needs_staff_review) {
            return ['status' => self::STATUS_NEEDS_STAFF];
        }

        return ['status' => self::STATUS_FOUND, 'profile' => $profile];
    }

    public function presentForKiosk(WaiverProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'email' => $profile->email,
            'phone' => $profile->phone_raw ?: $profile->phone_e164,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
            'dependents' => $profile->activeDependents->map(fn (WaiverProfileDependent $d) => [
                'id' => $d->id,
                'first_name' => $d->first_name,
                'last_name' => $d->last_name,
                'age' => $d->date_of_birth?->age,
                'relationship' => $d->relationship,
            ])->values()->all(),
        ];
    }

    public function resolveMinorsForSubmission(WaiverProfile $profile, array $selectedIds, array $newMinors): array
    {
        $selected = $profile->activeDependents()
            ->whereIn('id', array_filter(array_map('intval', $selectedIds)))
            ->get();

        $rows = $selected->map(fn (WaiverProfileDependent $d) => [
            'waiver_profile_dependent_id' => $d->id,
            'first_name' => $d->first_name,
            'last_name' => $d->last_name,
            'date_of_birth' => $d->date_of_birth?->toDateString(),
            'relationship' => $d->relationship,
            'was_new_this_visit' => false,
        ])->all();

        foreach ($newMinors as $minor) {
            $first = trim((string) ($minor['first_name'] ?? ''));
            $last = trim((string) ($minor['last_name'] ?? ''));
            if ($first === '' || $last === '') {
                continue;
            }
            $dob = $minor['date_of_birth'] ?? null;

            $existing = $profile->dependents->first(fn (WaiverProfileDependent $d) => $d->matches($first, $last, $dob));
            $dependent = $existing ?: $profile->dependents()->create([
                'first_name' => $first,
                'last_name' => $last,
                'date_of_birth' => $dob,
                'relationship' => $minor['relationship'] ?? null,
                'is_active' => true,
            ]);

            $rows[] = [
                'waiver_profile_dependent_id' => $dependent->id,
                'first_name' => $first,
                'last_name' => $last,
                'date_of_birth' => $dob,
                'relationship' => $minor['relationship'] ?? null,
                'was_new_this_visit' => true,
            ];
        }

        return $rows;
    }

    public function syncFromWaiver(Waiver $waiver): ?WaiverProfile
    {
        try {
            if ($waiver->status !== Waiver::STATUS_COMPLETED || !$waiver->company_id) {
                return null;
            }

            $digits = WaiverProfile::digitsFor($waiver->adult_phone);
            if (!$digits) {
                return null;
            }

            if ($waiver->waiver_profile_id) {
                $profile = WaiverProfile::find($waiver->waiver_profile_id);
                if ($profile) {
                    $this->stampVisit($profile, $waiver);

                    return $profile;
                }
            }

            $matches = WaiverProfile::where('company_id', $waiver->company_id)
                ->where('phone_digits', $digits)
                ->orderBy('id')
                ->get();

            if ($matches->count() > 1) {
                return null;
            }

            $profile = $matches->first();

            if (!$profile) {
                $profile = WaiverProfile::create([
                    'company_id' => $waiver->company_id,
                    'last_location_id' => $waiver->location_id,
                    'phone_digits' => $digits,
                    'phone_e164' => SmsService::toE164($waiver->adult_phone),
                    'phone_raw' => $waiver->adult_phone,
                    'first_name' => (string) $waiver->adult_first_name,
                    'last_name' => (string) $waiver->adult_last_name,
                    'email' => $waiver->adult_email,
                    'date_of_birth' => $waiver->adult_dob,
                ]);
            } elseif ($this->isDifferentPerson($profile, $waiver)) {
                $profile->update(['needs_staff_review' => true]);
            }

            if (!$waiver->waiver_profile_id) {
                $waiver->forceFill(['waiver_profile_id' => $profile->id])->saveQuietly();
            }

            $this->stampVisit($profile, $waiver);
            $this->seedDependentsFromWaiver($profile, $waiver);

            return $profile;
        } catch (\Throwable $e) {
            Log::warning('Waiver profile sync failed', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function isDifferentPerson(WaiverProfile $profile, Waiver $waiver): bool
    {
        $norm = fn (?string $v) => mb_strtolower(trim((string) $v));

        return $norm($profile->last_name) !== $norm($waiver->adult_last_name)
            || $norm($profile->first_name) !== $norm($waiver->adult_first_name);
    }

    protected function stampVisit(WaiverProfile $profile, Waiver $waiver): void
    {
        $profile->update([
            'last_location_id' => $waiver->location_id ?: $profile->last_location_id,
            'last_waiver_at' => $waiver->submitted_at ?: now(),
            'submissions_count' => $profile->waivers()->where('status', Waiver::STATUS_COMPLETED)->count(),
        ]);
    }

    protected function seedDependentsFromWaiver(WaiverProfile $profile, Waiver $waiver): void
    {
        $waiver->loadMissing('minors');
        $profile->loadMissing('dependents');

        foreach ($waiver->minors as $minor) {
            if ($minor->waiver_profile_dependent_id) {
                continue;
            }
            $dob = $minor->date_of_birth?->toDateString();
            $existing = $profile->dependents->first(fn (WaiverProfileDependent $d) => $d->matches($minor->first_name, $minor->last_name, $dob));

            $dependent = $existing ?: $profile->dependents()->create([
                'first_name' => $minor->first_name,
                'last_name' => $minor->last_name,
                'date_of_birth' => $dob,
                'relationship' => $minor->relationship,
                'is_active' => true,
            ]);

            $minor->forceFill(['waiver_profile_dependent_id' => $dependent->id])->saveQuietly();
            $profile->setRelation('dependents', $profile->dependents->push($dependent)->unique('id'));
        }
    }

    public function candidateGroups(int $companyId): Collection
    {
        return Waiver::where('company_id', $companyId)
            ->where('status', Waiver::STATUS_COMPLETED)
            ->whereNotNull('adult_phone')
            ->orderBy('submitted_at')
            ->get(['id', 'company_id', 'location_id', 'adult_first_name', 'adult_last_name', 'adult_email', 'adult_phone', 'adult_dob', 'submitted_at'])
            ->groupBy(fn (Waiver $w) => WaiverProfile::digitsFor($w->adult_phone) ?: 'unusable')
            ->filter(fn ($group, $key) => $key !== 'unusable');
    }
}
