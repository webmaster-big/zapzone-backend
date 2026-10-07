<?php

namespace App\Services;

use App\Http\Middleware\EnsureStaff;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StaffPinService
{
    private const DUMMY_HASH = '$2y$12$XFQVCxv03n1vkQjcBPUKG.ahHNkGjRP0/GVLTyzS0E3wiruC1hQgi';

    public const RESULT_OK = 'ok';
    public const RESULT_UNKNOWN = 'unknown';
    public const RESULT_LOCKED = 'locked';
    public const RESULT_BREAKER = 'breaker';
    public const RESULT_UNAVAILABLE = 'unavailable';

    public function isEnabled(): bool
    {
        return (bool) config('staff_pins.enabled') && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        $pepper = config('staff_pins.pepper');

        return is_string($pepper) && trim($pepper) !== '';
    }

    public function pinLength(): int
    {
        return max(4, min(10, (int) config('staff_pins.length', 6)));
    }

    public function pinRule(): string
    {
        return '/^\d{' . $this->pinLength() . '}$/';
    }

    public function digest(int $companyId, string $pin, ?string $pepper = null): string
    {
        $pepper ??= $this->pepper();

        return hash_hmac('sha256', $companyId . ':' . $pin, $pepper);
    }

    /**
     * Resolve a PIN to the employee who owns it. Returns [result, user].
     */
    public function resolve(int $companyId, string $pin, ?int $locationId = null): array
    {
        if (! $this->isConfigured()) {
            Log::warning('A staff PIN was presented but STAFF_PIN_PEPPER is not set; PIN sign-in is unavailable.');

            return [self::RESULT_UNAVAILABLE, null];
        }

        if ($this->breakerTripped($companyId)) {
            return [self::RESULT_BREAKER, null];
        }

        $digests = [$this->digest($companyId, $pin)];
        $previous = $this->previousPepper();

        if ($previous !== null) {
            $digests[] = $this->digest($companyId, $pin, $previous);
        }

        $candidate = User::query()
            ->where('company_id', $companyId)
            ->whereIn('pin_lookup', $digests)
            ->first();

        $verified = Hash::check($pin, $candidate?->pin_hash ?: self::DUMMY_HASH);

        if (! $candidate || ! $verified) {
            $this->recordFailure($companyId, $candidate);

            return [self::RESULT_UNKNOWN, null];
        }

        if ($candidate->pinIsLocked()) {
            return [self::RESULT_LOCKED, null];
        }

        if (! $this->eligible($candidate, $locationId)) {
            $this->recordFailure($companyId, null);

            return [self::RESULT_UNKNOWN, null];
        }

        $this->rotateLookupIfStale($candidate, $companyId, $pin);
        $this->clearFailures($candidate);

        return [self::RESULT_OK, $candidate];
    }

    public function setPin(User $target, string $pin, ?User $actor = null): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PINs are not set up on this server yet. Please ask your administrator to finish configuring them.');
        }

        if ($target->company_id === null) {
            throw new RuntimeException('This employee is not linked to a company yet, so a PIN cannot be issued.');
        }

        if (! in_array((string) $target->role, $this->allowedRoles(), true)) {
            throw new RuntimeException('That role cannot hold a PIN.');
        }

        $lookup = $this->digest((int) $target->company_id, $pin);

        $taken = User::query()
            ->where('pin_lookup', $lookup)
            ->where('id', '!=', $target->getKey())
            ->exists();

        if ($taken) {
            throw new RuntimeException('That PIN is already in use. Please choose a different one.');
        }

        try {
            $target->forceFill([
                'pin_hash' => Hash::make($pin),
                'pin_lookup' => $lookup,
                'pin_lookup_v' => 1,
                'pin_set_at' => now(),
                'pin_set_by' => $actor?->getKey(),
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
            ])->save();
        } catch (QueryException $e) {
            throw new RuntimeException('That PIN is already in use. Please choose a different one.');
        }

        ActivityLog::log(
            'staff_pin_set',
            'security',
            ($actor && $actor->getKey() !== $target->getKey()
                ? ActivityLog::describeActor($actor) . ' issued a PIN to ' . ActivityLog::describeActor($target)
                : ActivityLog::describeActor($target) . ' set their own PIN'),
            $actor?->getKey() ?? $target->getKey(),
            $target->location_id,
            'user',
            $target->getKey()
        );
    }

    public function clearPin(User $target, ?User $actor = null): void
    {
        $target->forceFill([
            'pin_hash' => null,
            'pin_lookup' => null,
            'pin_set_at' => null,
            'pin_set_by' => $actor?->getKey(),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        ActivityLog::log(
            'staff_pin_cleared',
            'security',
            ($actor ? ActivityLog::describeActor($actor) : 'Someone') . ' removed the PIN for ' . ActivityLog::describeActor($target),
            $actor?->getKey(),
            $target->location_id,
            'user',
            $target->getKey()
        );
    }

    public function unlock(User $target, ?User $actor = null): void
    {
        $target->forceFill([
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        ActivityLog::log(
            'staff_pin_unlocked',
            'security',
            ($actor ? ActivityLog::describeActor($actor) : 'Someone') . ' unlocked the PIN for ' . ActivityLog::describeActor($target),
            $actor?->getKey(),
            $target->location_id,
            'user',
            $target->getKey()
        );
    }

    public function allowedRoles(): array
    {
        return array_values(array_intersect(
            (array) config('staff_pins.roles', []),
            EnsureStaff::ROLES
        ));
    }

    private function eligible(User $candidate, ?int $locationId): bool
    {
        if ((string) $candidate->status !== 'active') {
            return false;
        }

        if ($candidate->company_id === null) {
            return false;
        }

        if (! in_array((string) $candidate->role, $this->allowedRoles(), true)) {
            return false;
        }

        if ($locationId === null) {
            return true;
        }

        if (in_array((string) $candidate->role, ['company_admin', 'admin'], true)) {
            return true;
        }

        return $candidate->canWorkAt($locationId);
    }

    private function rotateLookupIfStale(User $candidate, int $companyId, string $pin): void
    {
        $current = $this->digest($companyId, $pin);

        if ($candidate->pin_lookup === $current) {
            return;
        }

        $candidate->forceFill([
            'pin_lookup' => $current,
            'pin_lookup_v' => (int) $candidate->pin_lookup_v + 1,
        ])->save();
    }

    private function recordFailure(int $companyId, ?User $candidate): void
    {
        $this->bumpBreaker($companyId);

        if (! $candidate) {
            return;
        }

        $attempts = (int) $candidate->pin_failed_attempts + 1;
        $max = max(1, (int) config('staff_pins.lockout.attempts', 5));

        $updates = ['pin_failed_attempts' => $attempts];

        if ($attempts >= $max) {
            $updates['pin_locked_until'] = now()->addMinutes(max(1, (int) config('staff_pins.lockout.minutes', 15)));
            $updates['pin_failed_attempts'] = 0;

            ActivityLog::log(
                'staff_pin_locked',
                'security',
                ActivityLog::describeActor($candidate) . ' had their PIN locked after ' . $max . ' wrong attempts',
                null,
                $candidate->location_id,
                'user',
                $candidate->getKey()
            );
        }

        $candidate->forceFill($updates)->save();
    }

    private function clearFailures(User $candidate): void
    {
        if ((int) $candidate->pin_failed_attempts === 0 && $candidate->pin_locked_until === null) {
            return;
        }

        $candidate->forceFill([
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();
    }

    private function breakerKey(int $companyId): string
    {
        return 'staff-pin:breaker:' . $companyId;
    }

    private function breakerTripped(int $companyId): bool
    {
        $limit = (int) config('staff_pins.company_breaker.failures_per_hour', 200);

        if ($limit <= 0) {
            return false;
        }

        return (int) Cache::get($this->breakerKey($companyId), 0) >= $limit;
    }

    private function bumpBreaker(int $companyId): void
    {
        $limit = (int) config('staff_pins.company_breaker.failures_per_hour', 200);

        if ($limit <= 0) {
            return;
        }

        $key = $this->breakerKey($companyId);
        $cooldown = max(1, (int) config('staff_pins.company_breaker.cooldown_minutes', 30));

        try {
            Cache::add($key, 0, now()->addMinutes($cooldown));
            $count = Cache::increment($key);

            if ((int) $count === $limit) {
                ActivityLog::log(
                    'staff_pin_breaker_tripped',
                    'security',
                    'PIN entry was suspended company-wide after ' . $limit . ' failed attempts in an hour',
                    null,
                    null,
                    'company',
                    $companyId
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Could not update the staff PIN breaker counter', ['error' => $e->getMessage()]);
        }
    }

    public function matchAmong(iterable $candidates, string $pin): ?User
    {
        foreach ($candidates as $candidate) {
            if ($candidate instanceof User && $candidate->pin_hash && Hash::check($pin, $candidate->pin_hash)) {
                return $candidate;
            }
        }

        return null;
    }

    private function pepper(): string
    {
        $pepper = config('staff_pins.pepper');

        if (! is_string($pepper) || trim($pepper) === '') {
            throw new RuntimeException('STAFF_PIN_PEPPER is not set.');
        }

        return $pepper;
    }

    private function previousPepper(): ?string
    {
        $pepper = config('staff_pins.pepper_previous');

        return is_string($pepper) && trim($pepper) !== '' ? $pepper : null;
    }
}
