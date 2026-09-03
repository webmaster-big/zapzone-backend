<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Waiver;
use App\Models\WaiverProfile;
use App\Models\WaiverProfileDependent;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaiverProfileService
{
    public const STATUS_FOUND = 'found';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_NEEDS_STAFF = 'needs_staff';

    public const SOURCE_PROFILE = 'profile';
    public const SOURCE_WAIVER_HISTORY = 'waiver_history';
    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_SHARED_PHONE = 'shared_phone';

    private const DEPENDENT_MAX_AGE = 18;

    public function lookup(int $companyId, ?string $phone): array
    {
        $digits = WaiverProfile::digitsFor($phone);
        if (!$digits) {
            return ['status' => self::STATUS_NOT_FOUND];
        }

        try {
            $matches = $this->profilesFor($companyId, $digits);

            if ($matches->count() > 1) {
                return ['status' => self::STATUS_NEEDS_STAFF];
            }

            $profile = $matches->first();
            $source = self::SOURCE_PROFILE;

            if (!$profile) {
                [$profile, $source] = $this->promoteFromHistory($companyId, $digits, $phone);
            }

            if (!$profile) {
                return $source === self::SOURCE_SHARED_PHONE
                    ? ['status' => self::STATUS_NEEDS_STAFF]
                    : ['status' => self::STATUS_NOT_FOUND];
            }

            if ($profile->needs_staff_review) {
                return ['status' => self::STATUS_NEEDS_STAFF];
            }

            return ['status' => self::STATUS_FOUND, 'profile' => $profile, 'source' => $source];
        } catch (\Throwable $e) {
            Log::error('Waiver returning lookup failed', [
                'company_id' => $companyId,
                'phone_last4' => substr($digits, -4),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::STATUS_NOT_FOUND, 'degraded' => true];
        }
    }

    protected function profilesFor(int $companyId, string $digits): Collection
    {
        return WaiverProfile::with(['activeDependents' => fn ($q) => $q->orderBy('first_name')])
            ->where('company_id', $companyId)
            ->where('phone_digits', $digits)
            ->orderBy('id')
            ->get();
    }

    private static function normalisedPhoneSql(string $column): string
    {
        $expr = "COALESCE({$column}, '')";
        foreach ([' ', '(', ')', '-', '.', '+'] as $strip) {
            $expr = "REPLACE({$expr}, '{$strip}', '')";
        }

        return $expr;
    }

    protected function promoteFromHistory(int $companyId, string $digits, ?string $phone): array
    {
        $lock = Cache::lock('waiver-profile-promote:' . $companyId . ':' . $digits, 15);

        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            Log::warning('Waiver profile promotion is already in flight for this number', [
                'company_id' => $companyId,
                'phone_last4' => substr($digits, -4),
            ]);

            return [null, self::SOURCE_PROFILE];
        }

        try {
            $existing = $this->profilesFor($companyId, $digits);

            if ($existing->count() > 1) {
                return [null, self::SOURCE_SHARED_PHONE];
            }

            if ($existing->isNotEmpty()) {
                return [$existing->first(), self::SOURCE_PROFILE];
            }

            return $this->promoteWithinLock($companyId, $digits, $phone);
        } finally {
            $lock->release();
        }
    }

    protected function promoteWithinLock(int $companyId, string $digits, ?string $phone): array
    {
        $waivers = Waiver::with('minors')
            ->where('company_id', $companyId)
            ->where('status', Waiver::STATUS_COMPLETED)
            ->whereNull('waiver_profile_id')
            ->whereNotNull('adult_phone')
            ->whereRaw(sprintf('RIGHT(%s, ?) = ?', self::normalisedPhoneSql('adult_phone')), [strlen($digits), $digits])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        if ($waivers->isNotEmpty()) {
            $promoted = $this->profileFromWaiverHistory($companyId, $digits, $waivers);

            return $promoted
                ? [$promoted, self::SOURCE_WAIVER_HISTORY]
                : [null, self::SOURCE_SHARED_PHONE];
        }

        $contacts = Contact::where('company_id', $companyId)
            ->where(fn ($q) => $q->where('status', 'active')->orWhereNull('status'))
            ->whereNotNull('phone')
            ->whereRaw(sprintf('RIGHT(%s, ?) = ?', self::normalisedPhoneSql('phone')), [strlen($digits), $digits])
            ->orderByDesc('id')
            ->get();

        if ($contacts->isEmpty()) {
            return [null, self::SOURCE_PROFILE];
        }

        $distinctNames = $contacts
            ->map(fn (Contact $c) => mb_strtolower(trim((string) $c->first_name)) . '|' . mb_strtolower(trim((string) $c->last_name)))
            ->unique();

        if ($distinctNames->count() > 1) {
            return [null, self::SOURCE_SHARED_PHONE];
        }

        return [$this->profileFromContact($companyId, $digits, $contacts->first(), $phone), self::SOURCE_CONTACT];
    }

    protected function profileFromWaiverHistory(int $companyId, string $digits, Collection $waivers): ?WaiverProfile
    {
        $latest = $waivers->last();

        $distinctNames = $waivers
            ->map(fn (Waiver $w) => mb_strtolower(trim((string) $w->adult_first_name)) . '|' . mb_strtolower(trim((string) $w->adult_last_name)))
            ->unique();

        if ($distinctNames->count() > 1) {
            return null;
        }

        return DB::transaction(function () use ($companyId, $digits, $waivers, $latest) {
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
            ]);

            foreach ($waivers as $waiver) {
                $waiver->forceFill(['waiver_profile_id' => $profile->id])->saveQuietly();
                $this->seedDependentsFromWaiver($profile, $waiver);
            }

            $this->stampVisit($profile, $latest);

            Log::info('Waiver profile promoted from waiver history', [
                'profile_id' => $profile->id,
                'company_id' => $companyId,
                'waivers_linked' => $waivers->count(),
                'dependents' => $profile->dependents()->count(),
            ]);

            return $profile->fresh(['activeDependents']);
        });
    }

    protected function profileFromContact(int $companyId, string $digits, Contact $contact, ?string $phone): ?WaiverProfile
    {
        $first = trim((string) $contact->first_name);
        $last = trim((string) $contact->last_name);

        if ($first === '' || $last === '') {
            return null;
        }

        $raw = $contact->phone ?: $phone;

        $profile = WaiverProfile::create([
            'company_id' => $companyId,
            'last_location_id' => $contact->location_id,
            'phone_digits' => $digits,
            'phone_e164' => SmsService::toE164($raw),
            'phone_raw' => $raw,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $contact->email,
            'date_of_birth' => $contact->date_of_birth,
        ]);

        Log::info('Waiver profile promoted from a contact', [
            'profile_id' => $profile->id,
            'company_id' => $companyId,
            'contact_id' => $contact->id,
        ]);

        return $profile->fresh(['activeDependents']);
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

            $lock = Cache::lock('waiver-profile-promote:' . $waiver->company_id . ':' . $digits, 15);

            try {
                $lock->block(3);
            } catch (LockTimeoutException) {
                Log::warning('Waiver profile sync skipped; another write holds this number', [
                    'waiver_id' => $waiver->id,
                    'company_id' => $waiver->company_id,
                ]);

                return null;
            }

            try {
                return $this->syncWithinLock($waiver, $digits);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('Waiver profile sync failed', [
                'waiver_id' => $waiver->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function syncWithinLock(Waiver $waiver, string $digits): ?WaiverProfile
    {
        try {
            $matches = WaiverProfile::where('company_id', $waiver->company_id)
                ->where('phone_digits', $digits)
                ->orderBy('id')
                ->get();

            if ($matches->count() > 1) {
                Log::warning('Waiver profile sync skipped; this number answers to more than one record', [
                    'waiver_id' => $waiver->id,
                    'company_id' => $waiver->company_id,
                    'profile_ids' => $matches->pluck('id')->all(),
                ]);

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

            if ($minor->date_of_birth && $minor->date_of_birth->age >= self::DEPENDENT_MAX_AGE) {
                continue;
            }

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
