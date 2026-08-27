<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Resources;

use App\Domains\Assets\Http\Resources\AssetAttachmentResource;
use App\Models\Attachment;
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
        return [
            'id' => $this->uuid,
            'filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'is_image' => $this->mime_type !== null && str_starts_with($this->mime_type, 'image/'),
            'uploaded_by' => $this->uploadedBy?->fullName(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'url' => '/tickets/attachments/'.$this->uuid,
        ];
    }
}
