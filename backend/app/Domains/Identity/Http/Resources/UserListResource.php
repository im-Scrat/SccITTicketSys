<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact user row for the directory table (SRS v1.2 FR-USER directory).
 * Uuid-only; no credential material. `archived` reflects the soft-delete state
 * so the table can badge archived rows.
 *
 * @mixin User
 */
class UserListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->fullName(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'employee_number' => $this->employee_number,
            'contact_number' => $this->contact_number,
            'role' => [
                'slug' => $this->role?->slug,
                'name' => $this->role?->name,
            ],
            'status' => $this->status->value,
            'force_password_reset' => $this->force_password_reset,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
