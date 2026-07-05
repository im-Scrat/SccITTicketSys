<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AccountLockService;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Clear a login lockout so the user can sign in again immediately (SRS
 * FR-AUTH-006; FR-USER admin action "Unlock account"). Delegates the actual
 * RateLimiter key clearing to AccountLockService and records how many throttle
 * keys were cleared for the audit trail.
 */
class UnlockAccount
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountLockService $locks,
    ) {}

    public function handle(User $user, User $admin, Request $request): User
    {
        $cleared = $this->locks->clear($user);

        $this->audit->activity(
            ActivityAction::AccountUnlocked,
            actor: $admin,
            subject: $user,
            properties: ['cleared_keys' => $cleared],
            request: $request,
            description: 'Account unlocked',
        );

        return $user;
    }
}
