<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Role change input (SRS FR-USER-003). One role per user; the target slug must
 * exist. Per-record authorization (and the last-admin invariant) is enforced by
 * the policy / ChangeUserRole action.
 */
class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && (bool) $this->user()?->can('changeRole', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::exists('roles', 'slug')],
        ];
    }
}
