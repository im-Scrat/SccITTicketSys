<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional reason for a suspension/deactivation (SRS FR-USER-007). The reason is
 * recorded in the audit trail (no dedicated column). Authorization + the
 * self/last-admin invariants are enforced by the policy / ChangeUserStatus.
 */
class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && (bool) $this->user()?->can('suspend', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
