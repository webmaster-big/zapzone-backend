<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

class StaffLocationSession
{
    public static function handle(TokenAuthenticated $event): void
    {
        $token = $event->token;

        if (! $token instanceof PersonalAccessToken || $token->active_location_id === null) {
            return;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            return;
        }

        if (! $user->enterLocation((int) $token->active_location_id)) {
            $token->forceFill(['active_location_id' => null]);
        }
    }

    public static function remember(PersonalAccessToken $token, User $user, int $locationId): void
    {
        $home = $user->homeLocationId();

        $token->forceFill([
            'active_location_id' => $home !== null && $locationId === $home ? null : $locationId,
        ])->save();
    }

    public static function startAt(PersonalAccessToken $token, User $user, ?int $locationId): void
    {
        if ($locationId === null || $locationId === $user->homeLocationId() || ! $user->canHoldSeveralLocations() || ! $user->canWorkAt($locationId)) {
            return;
        }

        self::remember($token, $user, $locationId);
        $user->enterLocation($locationId);
    }
}
