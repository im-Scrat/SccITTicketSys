<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirm an import (Phase 2.10 R6): the revision and the counts the
 * administrator was shown, so a changed plan is refused rather than saved.
 */
class ConfirmAssetImportRequest extends FormRequest
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
            'revision' => ['required', 'integer', 'min:1'],
            'expected' => ['required', 'array'],
            'expected.pc_units' => ['required', 'integer', 'min:0'],
            'expected.assets' => ['required', 'integer', 'min:0'],
            'expected.catalog_entries' => ['required', 'integer', 'min:0'],
        ];
    }
}
