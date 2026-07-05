<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Rules\PasswordPolicy;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrator user-creation input (SRS FR-USER-001/002). Unlike public
 * registration, any of the three system roles may be assigned here (this is how
 * a second Administrator is provisioned). The account is created active unless a
 * different non-terminal status is chosen.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::exists('roles', 'slug')],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_number')],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'suspended', 'pending'])],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy],
            'force_password_reset' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
            'role.exists' => 'The selected role is invalid.',
        ];
    }
}
