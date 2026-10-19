<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;

/** Exclude or include many review rows at once (Phase 2.10 R6). */
class BulkAssetImportRowsRequest extends FormRequest
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
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['integer', 'min:1'],
            'exclude' => ['required', 'boolean'],
        ];
    }
}
