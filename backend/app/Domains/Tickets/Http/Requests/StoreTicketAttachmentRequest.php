<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Domains\Assets\Http\Requests\StoreAssetAttachmentRequest;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a ticket attachment upload (SRS FR-TKT-008, NFR-SEC-007/008).
 *
 * Mirrors {@see StoreAssetAttachmentRequest} exactly, including the dual
 * MIME + extension allow-list: `mimetypes:` checks the **detected** type of the
 * uploaded bytes, so a renamed executable is rejected regardless of extension,
 * and `extensions:` closes the double-extension trick where a browser might
 * re-interpret `x.php.png`.
 *
 * One deliberate difference: tickets accept a slightly wider set, because a
 * teacher photographing a fault may reasonably send a phone screenshot or a
 * short error-log text file, neither of which is asset evidence.
 *
 * The size ceiling is the same configurable `security.uploads.max_kb`, so a
 * deployment tightens both surfaces at once rather than leaving one behind.
 */
class StoreTicketAttachmentRequest extends FormRequest
{
    /** Kilobytes. 10 MB by default, matching FR-TKT-008. */
    private const DEFAULT_MAX_KB = 10240;

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && (bool) $this->user()?->can('manageAttachments', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('security.uploads.max_kb', self::DEFAULT_MAX_KB);

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimetypes:image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain',
                'extensions:png,jpg,jpeg,webp,gif,pdf,txt,log',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Attach an image (PNG, JPEG, WebP, GIF), a PDF, or a plain-text log.',
            'file.extensions' => 'Attach an image (PNG, JPEG, WebP, GIF), a PDF, or a plain-text log.',
            'file.max' => 'That file is too large.',
        ];
    }
}
