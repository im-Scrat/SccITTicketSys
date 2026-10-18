<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\ImportCatalogDecision;
use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Decide a catalog proposal (Phase 2.10 R6, D5). */
class DecideAssetImportCatalogRequest extends FormRequest
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
            'decision' => ['required', 'string', Rule::in(ImportCatalogDecision::values())],
            'model' => ['nullable', 'required_if:decision,existing', 'integer', 'min:1'],
            'manufacturer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model_number' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
