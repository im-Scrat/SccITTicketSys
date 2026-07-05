<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Central account-safety invariants shared by UserPolicy (authorization) and the
 * lifecycle Actions (defense-in-depth). Encodes the "least privilege / no
 * self-lockout" rules from the SRS security requirements: an administrator must
 * never be able to remove the system's last route to administration, nor perform
 * destructive actions on their own account.
 */
class UserGuard
{
    /**
     * True when disabling/removing this user would leave the platform with no
     * active administrator (i.e. they are the sole remaining active admin).
     */
    public function isLastActiveAdministrator(User $user): bool
    {
        if (! $user->isAdministrator() || $user->status !== UserStatus::Active) {
            return false;
        }

        return $this->activeAdministratorCount() <= 1;
    }

    /**
     * True when demoting this user away from the Administrator role would remove
     * the last active administrator.
     */
    public function wouldOrphanAdministration(User $user, string $newRoleSlug): bool
    {
        return $newRoleSlug !== 'administrator' && $this->isLastActiveAdministrator($user);
    }

    private function activeAdministratorCount(): int
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('role', fn (Builder $q): Builder => $q->where('slug', 'administrator'))
            ->count();
    }
}
