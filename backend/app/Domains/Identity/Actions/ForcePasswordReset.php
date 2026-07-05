<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Require (or clear the requirement for) a password change at the user's next
 * sign-in (SRS FR-USER admin action "Force password reset"). Enforced
 * server-side by EnsurePasswordIsCurrent and cleared automatically when the user
 * changes their password (ChangeUserPassword / password reset).
 */
class ForcePasswordReset
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, bool $required, User $admin, Request $request): User
    {
        $user->forceFill([
            'force_password_reset' => $required,
            'updated_by' => $admin->getKey(),
        ])->save();

        $this->audit->activity(
            ActivityAction::ForcePasswordResetSet,
            actor: $admin,
            subject: $user,
            properties: ['required' => $required],
            request: $request,
            description: $required ? 'Password reset required at next sign-in' : 'Password reset requirement cleared',
        );

        return $user;
    }
}
