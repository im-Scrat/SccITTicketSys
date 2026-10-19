<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Resources;

use App\Models\ReportEmailDelivery;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report document as its requester sees it (Phase 2.10). No storage path,
 * hash input or request metadata; the download URL appears only once the file
 * can actually be fetched.
 *
 * @mixin ReportExport
 */
class ReportExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'report_type' => $this->report_type->value,
            'report_label' => $this->report_type->label(),
            'format' => $this->format->value,
            'format_label' => $this->format->label(),
            'purpose' => $this->purpose->value,
            'profile' => $this->profile->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'row_count' => $this->row_count,
            'truncated' => $this->truncated,
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'download_url' => $this->isDownloadable() ? '/api/admin/reports/exports/'.$this->uuid.'/download' : null,
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn (ReportEmailDelivery $d): array => [
                'recipient' => $d->recipient,
                'external' => $d->is_external,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'sent_at' => $d->sent_at?->toIso8601ZuluString(),
            ])->values()->all()),
        ];
    }
}
