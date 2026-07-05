<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Notifications\RegistrationApproved;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Re-send the registration approval email (SRS FR-USER admin action "Resend
 * approval email"). Reuses the existing RegistrationApproved notification so the
 * message stays identical to the original decision email.
 */
class ResendApprovalEmail
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, User $admin, Request $request): void
    {
        $user->notify(new RegistrationApproved);

        $this->audit->activity(
            ActivityAction::ApprovalEmailResent,
            actor: $admin,
            subject: $user,
            request: $request,
            description: 'Approval email resent',
        );
    }
}
