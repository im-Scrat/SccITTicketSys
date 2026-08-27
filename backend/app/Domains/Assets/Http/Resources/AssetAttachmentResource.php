<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\AssetAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One image or document in the attachment gallery (SRS FR-AST-002).
 *
 * `storage_path` is deliberately **never** exposed: files sit on a private disk
 * and are reached only through the authorized download route, addressed by uuid
 * (NFR-SEC-007/008). What the client gets is a route it may call, not a location
 * on disk it might try to fetch directly.
 *
 * @mixin AssetAttachment
 */
class AssetAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind,
            'is_image' => $this->isImage(),
            'filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'caption' => $this->caption,
            'uploaded_by' => $this->uploadedBy?->fullName(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            // Relative to the API root; the client resolves it through the same
            // session-authenticated axios instance as every other call.
            'url' => '/admin/asset-attachments/'.$this->uuid,
        ];
    }
}
