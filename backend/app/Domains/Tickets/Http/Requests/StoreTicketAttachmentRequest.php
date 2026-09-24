<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Domains\Assets\Http\Requests\StoreAssetAttachmentRequest;
use App\Models\Ticket;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a ticket attachment upload (SRS FR-TKT-008, NFR-SEC-007/008).
 *
 * The rules come from {@see AttachmentSecurity}, which owns the allow-list for
 * both upload surfaces. Mirroring {@see StoreAssetAttachmentRequest} by hand is
 * what let the two drift apart before; deriving both from one map means a type
 * cannot be permitted here and refused there, or — worse — validated against one
 * list and stored against another.
 *
 * The ticket profile is deliberately slightly wider than the asset profile: a
 * teacher reporting a fault may reasonably send a phone screenshot or a short
 * error-log text file, neither of which is asset evidence.
 *
 * The size ceiling is the same configurable `security.uploads.max_kb`, so a
 * deployment tightens both surfaces at once rather than leaving one behind.
 */
class StoreTicketAttachmentRequest extends FormRequest
{
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
        return [
            'file' => AttachmentSecurity::rules(AttachmentSecurity::PROFILE_TICKET),
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
