<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\Identity\Services\UserGuard;
use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * The single account-status transition for User Management (SRS FR-USER-007;
 * account status management). Handles activate / suspend / reactivate /
 * deactivate uniformly: it enforces the self-lockout and last-administrator
 * invariants, clears terminal rejection metadata when re-activating, invalidates
 * the effective-permission cache so access reflects the new state immediately
 * (via EnsureAccountIsActive), and records the precise audit event.
 */
class ChangeUserStatus
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly UserGuard $guard,
    ) {}

    public function handle(User $user, UserStatus $to, User $admin, Request $request, ?string $reason = null): User
    {
        $this->assertSafe($user, $to, $admin);

        $from = $user->status;

        $attributes = ['status' => $to->value, 'updated_by' => $admin->getKey()];

        if ($to === UserStatus::Active) {
            // A re-activated account is no longer a rejected/declined request.
            $attributes += ['rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null];
        }

        $user->forceFill($attributes)->save();
        $this->permissions->forget($user);

        $action = $this->auditAction($from, $to);

        $this->audit->activity(
            $action,
            actor: $admin,
            subject: $user,
            properties: array_filter([
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ], fn (mixed $v): bool => $v !== null),
            request: $request,
            description: $action->label(),
        );

        return $user->load('role');
    }

    private function assertSafe(User $user, UserStatus $to, User $admin): void
    {
        if (! in_array($to, [UserStatus::Suspended, UserStatus::Inactive], true)) {
            return;
        }

        $verb = $to === UserStatus::Suspended ? 'suspend' : 'deactivate';

        if ($user->is($admin)) {
            throw new AuthorizationException("You cannot {$verb} your own account.");
        }

        if ($this->guard->isLastActiveAdministrator($user)) {
            throw new AuthorizationException("You cannot {$verb} the last active administrator.");
        }
    }

    private function auditAction(UserStatus $from, UserStatus $to): ActivityAction
    {
        return match (true) {
            $to === UserStatus::Active && $from === UserStatus::Pending => ActivityAction::AccountActivated,
            $to === UserStatus::Active => ActivityAction::UserReactivated,
            $to === UserStatus::Suspended => ActivityAction::AccountSuspended,
            default => ActivityAction::AccountDeactivated,
        };
    }
}
