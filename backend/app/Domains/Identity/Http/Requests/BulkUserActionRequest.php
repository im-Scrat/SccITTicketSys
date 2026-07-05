<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Exports\UsersExport;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk administration input (SRS v1.2 FR-USER bulk operations). The coarse gate
 * here is `users.update`; the controller re-checks the per-record policy for
 * every selected user inside a transaction, so an operation that is not
 * permitted on one target aborts the whole batch (all-or-nothing).
 */
class BulkUserActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasPermissionTo('users.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([
                'activate', 'suspend', 'reactivate', 'deactivate', 'reject', 'role', 'notify', 'export',
            ])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', Rule::exists('users', 'uuid')],
            'role' => ['required_if:action,role', 'string', Rule::exists('roles', 'slug')],
            'reason' => ['nullable', 'string', 'max:1000'],
            'subject' => ['required_if:action,notify', 'string', 'max:150'],
            'message' => ['required_if:action,notify', 'string', 'max:5000'],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx'])],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', Rule::in(array_keys(UsersExport::COLUMNS))],
        ];
    }
}
