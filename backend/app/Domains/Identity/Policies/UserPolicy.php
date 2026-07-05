<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Services\UserGuard;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Per-record authorization for User Management (SRS FR-USER-*; SDD §11.1).
 * Route-level `can:users.*` middleware provides the coarse gate; these methods
 * add per-record rules and the account-safety invariants (no self-destructive
 * action, never orphan administration). The permission-slug Gate::before does
 * not short-circuit these abilities because the ability names here are not
 * permission slugs — so deny-by-default still falls through to this policy.
 */
class UserPolicy
{
    public function __construct(private readonly UserGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('users.view') || $actor->hasPermissionTo('users.update');
    }

    public function view(User $actor, User $target): bool
    {
        return $this->viewAny($actor);
    }

    public function viewAudit(User $actor, User $target): bool
    {
        return $this->viewAny($actor);
    }

    public function export(User $actor): bool
    {
        return $actor->hasPermissionTo('users.view') || $actor->hasPermissionTo('users.update');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('users.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update');
    }

    /** Archive (soft delete). Never self; never the last active administrator. */
    public function delete(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.delete')
            && $actor->isNot($target)
            && ! $this->guard->isLastActiveAdministrator($target);
    }

    public function restore(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.delete');
    }

    /** Move a user to Active from any non-active state. */
    public function activate(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update');
    }

    /** Suspend / deactivate. Never self; never the last active administrator. */
    public function suspend(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update')
            && $actor->isNot($target)
            && ! $this->guard->isLastActiveAdministrator($target);
    }

    public function changeRole(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update') && $actor->isNot($target);
    }

    public function managePermissions(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update') && $actor->isNot($target);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update');
    }

    public function unlock(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update');
    }

    /** Approve / reject / edit apply only to a pending registration request. */
    public function approve(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update')
            && $actor->isNot($target)
            && $target->status === UserStatus::Pending;
    }

    public function reject(User $actor, User $target): bool
    {
        return $this->approve($actor, $target);
    }

    public function updateRegistration(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.update')
            && $target->status === UserStatus::Pending;
    }
}
