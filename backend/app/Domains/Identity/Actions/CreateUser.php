<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administrator-driven user creation (SRS FR-USER-001/002/009). Unlike the
 * public registration workflow, an administrator-created account is active and
 * email-verified by default, is stamped with `created_by`/`updated_by`, and
 * carries `registration_source = admin`. Optionally requires a password change
 * at first sign-in (force_password_reset).
 */
class CreateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $admin, Request $request): User
    {
        $role = Role::query()->where('slug', $data['role'])->firstOrFail();
        $status = isset($data['status']) ? UserStatus::from((string) $data['status']) : UserStatus::Active;

        $user = DB::transaction(fn (): User => User::create([
            'role_id' => $role->id,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'employee_number' => $data['employee_number'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'password' => $data['password'], // 'hashed' cast hashes on set
            'password_changed_at' => now(),
            'force_password_reset' => (bool) ($data['force_password_reset'] ?? false),
            'status' => $status->value,
            'email_verified_at' => now(),
            'registration_source' => 'admin',
            'created_by' => $admin->getKey(),
            'updated_by' => $admin->getKey(),
        ]));

        $this->audit->activity(
            ActivityAction::UserCreated,
            actor: $admin,
            subject: $user,
            properties: ['role' => $role->slug, 'status' => $status->value],
            request: $request,
            description: 'User account created',
        );

        return $user->load('role');
    }
}
