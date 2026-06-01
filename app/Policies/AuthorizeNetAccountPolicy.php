<?php

namespace App\Policies;

use App\Models\AuthorizeNetAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AuthorizeNetAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->location_id !== null;
    }

    public function view(User $user, AuthorizeNetAccount $authorizeNetAccount): bool
    {
        return $user->location_id === $authorizeNetAccount->location_id;
    }

    public function create(User $user): bool
    {
        return $user->location_id !== null &&
               in_array($user->role, ['location_manager', 'company_admin']);
    }

    public function update(User $user, AuthorizeNetAccount $authorizeNetAccount): bool
    {
        return $user->location_id === $authorizeNetAccount->location_id &&
               in_array($user->role, ['location_manager', 'company_admin']);
    }

    public function delete(User $user, AuthorizeNetAccount $authorizeNetAccount): bool
    {
        return $user->location_id === $authorizeNetAccount->location_id &&
               in_array($user->role, ['location_manager', 'company_admin']);
    }

}
