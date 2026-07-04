<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Notifications\RegistrationApproved;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Approve a pending registration (SRS FR-AUTH-014/015). Activates the account,
 * clears any prior rejection metadata, records the decision (with the approving
 * Administrator) in the audit trail, and emails the applicant.
 */
class ApproveRegistration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(User $applicant, User $admin, Request $request): User
    {
        $applicant->forceFill([
            'status' => UserStatus::Active->value,
            'email_verified_at' => $applicant->email_verified_at ?? now(),
            'rejection_reason' => null,
            'rejected_by' => null,
            'rejected_at' => null,
        ])->save();

        $this->permissions->forget($applicant);

        $this->audit->activity(
            ActivityAction::RegistrationApproved,
            actor: $admin,
            subject: $applicant,
            properties: ['applicant' => $applicant->uuid],
            request: $request,
            description: 'Registration approved',
        );

        $applicant->notify(new RegistrationApproved);

        return $applicant;
    }
}
