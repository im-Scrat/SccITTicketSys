<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Notifications\RegistrationSubmitted;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create a registration request (SRS FR-AUTH-013; SDD §10.3). The account is
 * created `pending` — it cannot authenticate until an Administrator approves it.
 * Only Teacher/Technician roles are accepted (BR-01a); the caller (RegisterRequest)
 * has already constrained the role, and this action re-verifies defensively.
 */
class RegisterApplicant
{
    /** @var list<string> */
    private const SELF_REGISTERABLE = ['teacher', 'technician'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Request $request): User
    {
        $role = Role::query()
            ->whereIn('slug', self::SELF_REGISTERABLE)
            ->where('slug', $data['role'])
            ->firstOrFail();

        $user = DB::transaction(function () use ($data, $role): User {
            return User::create([
                'role_id' => $role->id,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'employee_number' => $data['employee_number'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'password' => $data['password'], // 'hashed' cast hashes on set
                'status' => UserStatus::Pending->value,
            ]);
        });

        $this->audit->activity(
            ActivityAction::Registered,
            actor: $user,
            subject: $user,
            properties: ['role' => $role->slug],
            request: $request,
            description: 'Registration request submitted',
        );

        $user->notify(new RegistrationSubmitted);

        return $user;
    }
}
