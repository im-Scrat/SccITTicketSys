<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates directory list parameters (SRS v1.2 FR-USER directory). The `sort`
 * value is constrained to the same allow-list the UserDirectoryQuery resolves,
 * so an out-of-range sort is a 422 rather than silently ignored.
 */
class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([...UserStatus::values(), 'all'])],
            'role' => ['nullable', 'string', 'max:50'],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in(['name', 'email', 'status', 'role', 'created_at', 'last_login_at'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
