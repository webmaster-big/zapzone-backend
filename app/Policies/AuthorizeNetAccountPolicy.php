<?php

namespace App\Policies;

use App\Models\AuthorizeNetAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AuthorizeNetAccountPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Any authenticated user with a location can view their account
        return $user->location_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AuthorizeNetAccount $authorizeNetAccount): bool
    {
        // User can only view if the account belongs to their location
        return $user->location_id === $authorizeNetAccount->location_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // User must have a location assigned
        // Additional check: only location_manager or company_admin can create
        return $user->location_id !== null &&
               in_array($user->role, ['location_manager', 'company_admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AuthorizeNetAccount $authorizeNetAccount): bool
    {
        // User can only update if the account belongs to their location
        // and they have appropriate role
        return $user->location_id === $authorizeNetAccount->location_id &&
               in_array($user->role, ['location_manager', 'company_admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AuthorizeNetAccount $authorizeNetAccount): bool
    {
        // User can only delete if the account belongs to their location
        // and they have appropriate role
        return $user->location_id === $authorizeNetAccount->location_id &&
               in_array($user->role, ['location_manager', 'company_admin']);
    }

}
