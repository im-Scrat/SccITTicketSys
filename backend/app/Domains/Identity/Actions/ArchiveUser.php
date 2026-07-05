<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\Identity\Services\UserGuard;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Archive (soft-delete) a user (SRS FR-USER-001/006). Never a hard delete: the
 * row is retained so every attribution — tickets, comments, audit history,
 * foreign keys — stays intact. Enforces the self and last-administrator
 * invariants and stamps `updated_by` before the soft delete.
 */
class ArchiveUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly UserGuard $guard,
    ) {}

    public function handle(User $user, User $admin, Request $request): User
    {
        if ($user->is($admin)) {
            throw new AuthorizationException('You cannot archive your own account.');
        }

        if ($this->guard->isLastActiveAdministrator($user)) {
            throw new AuthorizationException('You cannot archive the last active administrator.');
        }

        $user->forceFill(['updated_by' => $admin->getKey()])->save();
        $user->delete(); // soft delete (deleted_at)
        $this->permissions->forget($user);

        $this->audit->activity(
            ActivityAction::UserArchived,
            actor: $admin,
            subject: $user,
            request: $request,
            description: 'User account archived',
        );

        return $user;
    }
}
