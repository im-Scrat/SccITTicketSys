<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public registration request (SRS FR-AUTH-013, BR-01a). Role is constrained to
 * Teacher/Technician here and re-verified in RegisterApplicant — Administrator
 * accounts can never be self-registered.
 */
class RegisterRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_number')],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'string', Rule::in(['teacher', 'technician'])],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Only Teacher and Technician registrations are accepted.',
            'email.unique' => 'An account with this email already exists.',
        ];
    }
}
