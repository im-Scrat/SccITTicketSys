<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a pending registration before the approve/reject decision (SRS
 * FR-AUTH-014 "Edit registration details before approval"). Role, if changed,
 * stays within the self-registerable set (Teacher/Technician); Administrator is
 * never assignable through the registration flow.
 */
class UpdateRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && (bool) $this->user()?->can('updateRegistration', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $id = $target instanceof User ? $target->getKey() : null;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_number')->ignore($id)],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'role' => ['nullable', 'string', Rule::in(['teacher', 'technician'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Only Teacher and Technician roles are valid for a registration request.',
            'email.unique' => 'An account with this email already exists.',
        ];
    }
}
