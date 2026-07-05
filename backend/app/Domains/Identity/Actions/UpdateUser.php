<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Edit a user's profile fields (SRS FR-USER-001/009). Scope is the mutable
 * profile only — role, status, and password each have their own dedicated
 * action so authorization and audit stay precise. Records the changed keys (not
 * values) in the audit properties and stamps `updated_by`.
 */
class UpdateUser
{
    /** @var list<string> */
    private const FIELDS = ['first_name', 'middle_name', 'last_name', 'email', 'employee_number', 'contact_number'];

    public function __construct(private readonly AuditLogger $audit) {}

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

        if ($changes !== []) {
            $user->updated_by = $admin->getKey();
            $user->save();

            $this->audit->activity(
                ActivityAction::UserUpdated,
                actor: $admin,
                subject: $user,
                properties: ['fields' => $changes],
                request: $request,
                description: 'User profile updated',
            );
        }

        return $user->load('role');
    }
}
