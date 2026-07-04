<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a password reset (SRS FR-AUTH-008). The token is verified by the
 * Laravel password broker in the controller.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy],
        ];
    }
}
