<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registration request as presented to Administrators in the review queue
 * (SRS FR-AUTH-014). Uuid-only; no internal id or credential material.
 *
 * @mixin User
 */
class RegistrationResource extends JsonResource
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
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'employee_number' => $this->employee_number,
            'contact_number' => $this->contact_number,
            'role' => [
                'slug' => $this->role?->slug,
                'name' => $this->role?->name,
            ],
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
        ];
    }
}
