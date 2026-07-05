<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Notifications\RegistrationRejected;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Re-send the registration rejection email (SRS FR-USER admin action "Resend
 * rejection email"), including the stored rejection reason. Reuses the existing
 * RegistrationRejected notification.
 */
class ResendRejectionEmail
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, User $admin, Request $request): void
    {
        $user->notify(new RegistrationRejected($user->rejection_reason));

        $this->audit->activity(
            ActivityAction::RejectionEmailResent,
            actor: $admin,
            subject: $user,
            request: $request,
            description: 'Rejection email resent',
        );
    }
}
