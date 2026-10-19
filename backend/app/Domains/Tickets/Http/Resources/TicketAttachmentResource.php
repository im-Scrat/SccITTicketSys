<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Resources;

use App\Domains\Assets\Http\Resources\AssetAttachmentResource;
use App\Models\Attachment;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ticket attachment (SRS FR-TKT-008).
 *
 * `storage_path` is deliberately absent, exactly as in
 * {@see AssetAttachmentResource}: the file sits on a private disk and the only
 * way to retrieve it is the download route below, which re-checks the owning
 * ticket's visibility on every request. The client receives a route it may
 * call, never a location on disk it might try to fetch.
 *
 * @mixin Attachment
 */
class TicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $safeMime = AttachmentSecurity::isAllowed($this->mime_type, AttachmentSecurity::PROFILE_TICKET)
            ? $this->mime_type
            : null;

        return [
            'id' => $this->uuid,
            'filename' => $this->original_filename,
            // Re-checked against the allow-list rather than read straight off the
            // row: a legacy value written before AttachmentSecurity existed must
            // not be able to drive the client's rendering decision either.
            'mime_type' => $safeMime,
            'file_size' => $this->file_size,
            'is_image' => $safeMime !== null
                && AttachmentSecurity::kindFor($safeMime) === AttachmentSecurity::KIND_IMAGE,
            'uploaded_by' => $this->uploadedBy?->fullName(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'url' => '/tickets/attachments/'.$this->uuid,
        ];
    }
}
