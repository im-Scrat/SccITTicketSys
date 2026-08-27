<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Http\Requests\Concerns\ValidatesFilterLists;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\PcCondition;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates asset directory list parameters (SRS FR-AST-011).
 *
 * Location scopes are uuids, never numeric ids; `sort` is allow-listed to match
 * `AssetDirectoryQuery::ASSET_SORTABLE`. `status` and `category` accept a
 * comma-separated list so one dashboard tile can link to several states at once,
 * which is why they validate as strings here and are narrowed against the enum
 * inside the directory query rather than by a plain `Rule::in`.
 */
class IndexAssetsRequest extends FormRequest
{
    use ValidatesFilterLists;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Asset::class);
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
            'category' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'technician' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'uuid'],
            'floor' => ['nullable', 'string', 'uuid'],
            'room' => ['nullable', 'string', 'uuid'],
            'warranty_expiring' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in([
                'asset_tag', 'name', 'status', 'condition', 'serial_number',
                'purchase_date', 'warranty_expiration', 'created_at', 'updated_at',
                'model', 'category', 'room', 'building', 'supplier', 'technician',
            ])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Reject a status/category/condition list that contains nothing the enum
     * knows. Silently ignoring the whole filter would show the operator an
     * unfiltered directory while their filter chip still reads "Maintenance".
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateFilterList($validator, 'status', AssetStatus::values()),
            fn (Validator $validator) => $this->validateFilterList($validator, 'condition', PcCondition::values()),
            fn (Validator $validator) => $this->validateFilterList($validator, 'category', ComponentType::values()),
        ];
    }
}
