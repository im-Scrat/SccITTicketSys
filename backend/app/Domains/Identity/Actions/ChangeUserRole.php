<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\Identity\Services\UserGuard;
use App\Enums\ActivityAction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Change a user's (single) role (SRS FR-USER-003; OI-01 one-role-per-user). The
 * effective-permission cache is invalidated so the new role's permissions apply
 * immediately (never bypassing the PermissionResolver). Demoting the last active
 * administrator away from `administrator` is refused.
 */
class ChangeUserRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly UserGuard $guard,
    ) {}

    public function handle(User $user, string $roleSlug, User $admin, Request $request): User
    {
        if ($this->guard->wouldOrphanAdministration($user, $roleSlug)) {
            throw new AuthorizationException('You cannot demote the last active administrator.');
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $from = $user->role?->slug;

        if ($from !== $role->slug) {
            $user->forceFill(['role_id' => $role->id, 'updated_by' => $admin->getKey()])->save();
            $this->permissions->forget($user);

            $this->audit->activity(
                ActivityAction::RoleChanged,
                actor: $admin,
                subject: $user,
                properties: ['from' => $from, 'to' => $role->slug],
                request: $request,
                description: 'Role changed',
            );
        }

        return $user->load('role');
    }
}
