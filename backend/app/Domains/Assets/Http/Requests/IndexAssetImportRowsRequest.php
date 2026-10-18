<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportRowStatus;
use App\Enums\ImportEntityType;
use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** List an import's rows (Phase 2.10 R6). */
class IndexAssetImportRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', AssetImport::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(AssetImportRowStatus::values())],
            'outcome' => ['nullable', 'string', Rule::in(AssetImportRowOutcome::values())],
            'type' => ['nullable', 'string', Rule::in(ImportEntityType::values())],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
