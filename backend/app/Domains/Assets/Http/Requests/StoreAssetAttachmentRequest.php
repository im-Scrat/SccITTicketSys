<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\Asset;
use App\Models\PcUnit;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an asset/PC attachment upload (SRS FR-AST-002, NFR-SEC-007/008).
 *
 * The rules come from {@see AttachmentSecurity}, which owns the allow-list for
 * this surface and for tickets. Two layers of file typing survive that move,
 * deliberately:
 *
 *  - `mimetypes:` checks the **detected** type of the uploaded bytes, so a
 *    renamed executable is rejected regardless of its extension;
 *  - `extensions:` additionally pins the filename, closing the double-extension
 *    trick where a browser might re-interpret `x.php.png`.
 *
 * Neither the validated request nor the payload decides what is *stored*: the
 * Action re-derives the type through {@see AttachmentSecurity::detect()}, so the
 * persisted value cannot come from a different source than the validated one.
 *
 * The size ceiling is configurable so a deployment can tighten it without a code
 * change; the default keeps a phone photo comfortably within reach.
 */
class StoreAssetAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('asset') ?? $this->route('pc_unit');

        if ($target instanceof Asset) {
            return (bool) $this->user()?->can('manageAttachments', $target);
        }

        if ($target instanceof PcUnit) {
            return (bool) $this->user()?->can('manageAttachments', $target);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => AttachmentSecurity::rules(AttachmentSecurity::PROFILE_ASSET),
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Attach a PNG, JPEG or WebP image, or a PDF document.',
            'file.extensions' => 'Attach a PNG, JPEG or WebP image, or a PDF document.',
            'file.max' => 'That file is too large.',
        ];
    }
}
