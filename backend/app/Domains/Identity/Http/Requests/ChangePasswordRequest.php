<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a self-service password change (SRS FR-AUTH-011). Requires
 * re-entering the current password; the new password must satisfy the policy
 * and be confirmed.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'different:current_password', 'confirmed', new PasswordPolicy],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'Your new password must be different from your current password.',
        ];
    }
}
