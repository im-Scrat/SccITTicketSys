<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated principal returned to the SPA (SDD DD-15). Exposes the
 * public `uuid` (never the internal id) and the effective permission slugs the
 * client uses for UX gating only — the server remains the authority.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name' => $this->fullName(),
            'email' => $this->email,
            'employee_number' => $this->employee_number,
            'contact_number' => $this->contact_number,
            'status' => $this->status->value,
            'role' => [
                'slug' => $this->role?->slug,
                'name' => $this->role?->name,
            ],
            'permissions' => $this->effectivePermissions(),
            'force_password_reset' => (bool) $this->force_password_reset,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
        ];
    }
}
