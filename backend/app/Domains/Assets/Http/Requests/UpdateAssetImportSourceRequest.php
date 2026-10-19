<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Services\Import\SpreadsheetReader;
use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Read an import's file again with another sheet, header row, delimiter or
 * encoding (Phase 2.10 R6). `tab` stands for the tab character.
 */
class UpdateAssetImportSourceRequest extends FormRequest
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
            'sheet' => ['nullable', 'string', 'max:255'],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'delimiter' => ['nullable', 'string', Rule::in([',', ';', 'tab', '|'])],
            'encoding' => ['nullable', 'string', Rule::in(SpreadsheetReader::ENCODINGS)],
        ];
    }

    /**
     * @return array{sheet: string|null, header_row: int|null, delimiter: string|null, encoding: string|null}
     */
    public function options(): array
    {
        $delimiter = $this->input('delimiter');

        return [
            'sheet' => $this->input('sheet'),
            'header_row' => $this->filled('header_row') ? $this->integer('header_row') : null,
            'delimiter' => $delimiter === 'tab' ? "\t" : $delimiter,
            'encoding' => $this->input('encoding'),
        ];
    }
}
