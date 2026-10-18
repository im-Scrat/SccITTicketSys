<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\AssetImportEntityMode;
use App\Models\AssetImport;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload an inventory spreadsheet (Phase 2.10 R6). The file rules come from
 * the one upload trust boundary's spreadsheet profile; the content itself is
 * inspected again by `CreateAssetImport`.
 */
class StoreAssetImportRequest extends FormRequest
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
            'file' => AttachmentSecurity::rules(AttachmentSecurity::PROFILE_SPREADSHEET),
            'entity_mode' => ['required', 'string', Rule::in(AssetImportEntityMode::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Upload an Excel workbook (.xlsx) or a CSV file.',
            'file.extensions' => 'Upload an Excel workbook (.xlsx) or a CSV file.',
            'file.max' => 'That file is larger than 10 MB. Split it into smaller files.',
            'entity_mode.required' => 'Choose what the file holds.',
        ];
    }
}
