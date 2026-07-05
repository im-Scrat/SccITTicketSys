<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Administrator-initiated password reset (SRS FR-USER admin action "Reset
 * password" / "Resend password reset email"). Reuses Laravel's single-use,
 * time-limited token broker — the administrator never sees or sets the user's
 * password; the user receives the same secure reset link as the self-service
 * flow (which stamps `password_changed_at` and clears force_password_reset).
 */
class AdminSendPasswordReset
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, User $admin, Request $request): void
    {
        Password::sendResetLink(['email' => $user->email]);

        $this->audit->activity(
            ActivityAction::PasswordResetEmailResent,
            actor: $admin,
            subject: $user,
            request: $request,
            description: 'Password reset email sent',
        );
    }
}
