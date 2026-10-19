<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates building directory list parameters (SRS FR-LOC-001). The `sort`
 * value is constrained to the same allow-list LocationDirectoryQuery resolves,
 * so an out-of-range sort is a 422 rather than being silently ignored.
 */
class IndexBuildingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Building::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'string', Rule::in(['all', 'active', 'inactive', '1', '0', 'true', 'false'])],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in(['name', 'code', 'floors_count', 'rooms_count', 'created_at'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
