<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Notifications\RegistrationRejected;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Reject a pending registration (SRS FR-AUTH-014/015). Sets the terminal
 * `rejected` status, retains the reason and the deciding Administrator for
 * audit, and emails the applicant (including the reason when provided).
 */
class RejectRegistration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(User $applicant, User $admin, ?string $reason, Request $request): User
    {
        $applicant->forceFill([
            'status' => UserStatus::Rejected->value,
            'rejection_reason' => $reason,
            'rejected_by' => $admin->getKey(),
            'rejected_at' => now(),
        ])->save();

        $this->permissions->forget($applicant);

        $this->audit->activity(
            ActivityAction::RegistrationRejected,
            actor: $admin,
            subject: $applicant,
            properties: ['applicant' => $applicant->uuid, 'reason' => $reason],
            request: $request,
            description: 'Registration rejected',
        );

        $applicant->notify(new RegistrationRejected($reason));

        return $applicant;
    }
}
