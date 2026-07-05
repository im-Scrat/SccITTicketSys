<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Exports\UsersExport;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Export parameters (SRS v1.2 FR-USER export). Carries the same directory
 * filter/sort params as the list (so the export mirrors the current view) plus
 * the output format and an optional column subset (validated against the
 * exporter's allow-list).
 */
class ExportUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('export', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx'])],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', Rule::in(array_keys(UsersExport::COLUMNS))],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([...UserStatus::values(), 'all'])],
            'role' => ['nullable', 'string', 'max:50'],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in(['name', 'email', 'status', 'role', 'created_at', 'last_login_at'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function outputFormat(): string
    {
        return $this->string('format', 'xlsx')->toString() === 'csv' ? 'csv' : 'xlsx';
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        /** @var list<string> $columns */
        $columns = (array) $this->input('columns', []);

        return $columns;
    }
}
