<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Domains\Tickets\Http\Resources\TicketAttachmentResource;
use App\Models\RepairImage;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item of repair evidence (SRS FR-MNT-005/010; SDD DD-53).
 *
 * Deliberately shaped like {@see TicketAttachmentResource}: same fields, same
 * `kind` derivation, same absence of a storage path. The client never learns
 * where the file lives — the only way to the bytes is the download route, which
 * re-checks the owning record's policy on every request.
 *
 * `kind` is derived from the **stored, server-detected** MIME type through
 * {@see AttachmentSecurity::kindFor()}, so a document cannot present itself as
 * an image in a gallery by having been uploaded with a lying filename.
 *
 * @mixin RepairImage
 */
class RepairImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'image_type' => $this->image_type->value,
            'image_type_label' => $this->image_type->label(),
            'filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'kind' => AttachmentSecurity::kindFor($this->mime_type),
            'size' => $this->file_size,
            'caption' => $this->caption,
            'uploaded_by' => $this->uploadedBy !== null ? [
                'id' => $this->uploadedBy->uuid,
                'name' => $this->uploadedBy->fullName(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
