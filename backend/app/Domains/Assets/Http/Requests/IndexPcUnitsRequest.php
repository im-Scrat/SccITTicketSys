<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Http\Requests\Concerns\ValidatesFilterLists;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates PC-unit directory list parameters (SRS FR-PC-001).
 *
 * `sort` is allow-listed to match `AssetDirectoryQuery::PC_SORTABLE`; location
 * scopes are uuids. As with assets, `status` and `condition` accept a
 * comma-separated list and are narrowed against the enum in the after-hook so an
 * unrecognized value is reported rather than silently dropping the filter.
 */
class IndexPcUnitsRequest extends FormRequest
{
    use ValidatesFilterLists;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', PcUnit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'uuid'],
            'floor' => ['nullable', 'string', 'uuid'],
            'room' => ['nullable', 'string', 'uuid'],
            'warranty_expiring' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in([
                'unit_code', 'pc_name', 'asset_tag', 'hostname', 'status', 'current_condition',
                'purchase_date', 'warranty_expiration', 'created_at', 'updated_at', 'room', 'building',
            ])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateFilterList($validator, 'status', PcStatus::values()),
            fn (Validator $validator) => $this->validateFilterList($validator, 'condition', PcCondition::values()),
        ];
    }
}
