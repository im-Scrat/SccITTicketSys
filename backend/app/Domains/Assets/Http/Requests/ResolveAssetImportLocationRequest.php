<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\ImportLocationResolution;
use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Decide a location text's room (Phase 2.10 R6). */
class ResolveAssetImportLocationRequest extends FormRequest
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
            'resolution' => ['required', 'string', Rule::in(ImportLocationResolution::values())],
            'room' => ['bail', 'nullable', 'required_if:resolution,room', 'string', 'uuid'],
            'assign_anyway' => ['nullable', 'boolean'],
        ];
    }
}
