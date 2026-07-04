<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Per-record authorization for user/registration administration (SDD §11.1).
 * Route-level `can:users.update` gates the admin group; these policy methods add
 * per-record rules (e.g. only a *pending* request may be approved/rejected).
 * The permission-slug Gate::before does not short-circuit these abilities
 * because ability names here are not permission slugs.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('users.update');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('users.view') || $actor->hasPermissionTo('users.update');
    }

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
}
