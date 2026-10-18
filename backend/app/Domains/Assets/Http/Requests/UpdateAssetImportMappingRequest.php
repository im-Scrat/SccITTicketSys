<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\ImportDateFormat;
use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Save the column mapping, value meanings, defaults and date order (Phase
 * 2.10 R6). Shape only here; what is *acceptable* — required destinations,
 * one column per destination, a date order when one is needed — is decided by
 * `UpdateAssetImportMapping`, with the reasons the screen shows.
 */
class UpdateAssetImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', AssetImport::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['present', 'array', 'max:100'],
            'mapping.*' => ['nullable', 'string', 'max:64'],
            'value_mappings' => ['sometimes', 'array', 'max:8'],
            'value_mappings.*' => ['array', 'max:1000'],
            'value_mappings.*.*' => ['nullable', 'string', 'max:64'],
            'defaults' => ['sometimes', 'array'],
            'defaults.category' => ['nullable', 'string', 'max:64'],
            'defaults.pc_status' => ['nullable', 'string', 'max:64'],
            'defaults.asset_status' => ['nullable', 'string', 'max:64'],
            'defaults.condition' => ['nullable', 'string', 'max:64'],
            'defaults.location_from_sheet_name' => ['nullable', 'boolean'],
            'date_order' => ['nullable', 'string', Rule::in(ImportDateFormat::values())],
        ];
    }
}
