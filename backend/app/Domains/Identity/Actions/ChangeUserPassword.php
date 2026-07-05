<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Change the authenticated user's password (SRS FR-AUTH-011). The current
 * password has already been re-verified by ChangePasswordRequest. Updating the
 * hash causes every OTHER active session to be signed out on its next request
 * via App\Http\Middleware\AuthenticateSession (which re-stamps only the current
 * session); the current session stays valid.
 */
class ChangeUserPassword
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, string $newPassword, Request $request): void
    {
        $user->forceFill([
            'password' => $newPassword, // 'hashed' cast hashes
            'password_changed_at' => now(),
            'force_password_reset' => false, // requirement satisfied
        ])->save();

        $this->audit->activity(
            ActivityAction::PasswordChanged,
            actor: $user,
            subject: $user,
            request: $request,
            description: 'Password changed',
        );
    }
}
