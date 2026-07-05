<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\PermissionGrantType;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Replace a user's per-user permission overrides (SRS FR-USER-004). Overrides
 * grant or deny individual permissions on top of the role default; deny wins in
 * the PermissionResolver. This performs a full sync of the `user_permissions`
 * pivot to the desired set and invalidates the effective-permission cache. The
 * resolver — not this action — remains the authority on what "effective" means.
 */
class SetPermissionOverrides
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @param  list<string>  $grants  permission names to force-grant
     * @param  list<string>  $denies  permission names to force-deny
     */
    public function handle(User $user, array $grants, array $denies, User $admin, Request $request): User
    {
        $ids = Permission::query()
            ->whereIn('name', [...$grants, ...$denies])
            ->pluck('id', 'name');

        $sync = [];

        foreach ($grants as $name) {
            if (isset($ids[$name])) {
                $sync[$ids[$name]] = ['grant_type' => PermissionGrantType::Grant->value, 'granted_by' => $admin->getKey()];
            }
        }

        // Denies applied last so an accidental grant+deny of the same permission
        // resolves to deny (mirrors the resolver's deny-wins rule).
        foreach ($denies as $name) {
            if (isset($ids[$name])) {
                $sync[$ids[$name]] = ['grant_type' => PermissionGrantType::Deny->value, 'granted_by' => $admin->getKey()];
            }
        }

        $user->directPermissions()->sync($sync);
        $this->permissions->forget($user);

        $this->audit->activity(
            ActivityAction::PermissionsChanged,
            actor: $admin,
            subject: $user,
            properties: ['grants' => $grants, 'denies' => $denies],
            request: $request,
            description: 'Permission overrides updated',
        );

        return $user->load('role');
    }
}
