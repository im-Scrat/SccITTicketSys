<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Correct a pending registration's details before an approve/reject decision
 * (SRS FR-USER / FR-AUTH-014 — "Edit registration details before approval").
 * Only profile fields and the (self-registerable) requested role may change; the
 * account stays `pending`. Changing the role invalidates the permission cache.
 */
class UpdateRegistration
{
    /** @var list<string> */
    private const FIELDS = ['first_name', 'middle_name', 'last_name', 'email', 'employee_number', 'contact_number'];

    /** @var list<string> */
    private const SELF_REGISTERABLE = ['teacher', 'technician'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data, User $admin, Request $request): User
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $user->{$field}) {
                $user->{$field} = $data[$field];
                $changes[] = $field;
            }
        }

        $roleChanged = false;

        if (isset($data['role']) && in_array($data['role'], self::SELF_REGISTERABLE, true)) {
            $role = Role::query()->where('slug', $data['role'])->first();

            if ($role !== null && $role->id !== $user->role_id) {
                $user->role_id = $role->id;
                $changes[] = 'role';
                $roleChanged = true;
            }
        }

        if ($changes !== []) {
            $user->updated_by = $admin->getKey();
            $user->save();

            if ($roleChanged) {
                $this->permissions->forget($user);
            }

            $this->audit->activity(
                ActivityAction::RegistrationUpdated,
                actor: $admin,
                subject: $user,
                properties: ['fields' => $changes],
                request: $request,
                description: 'Registration details updated before decision',
            );
        }

        return $user->load('role');
    }
}
