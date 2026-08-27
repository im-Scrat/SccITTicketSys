<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\QrCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A QR label (SRS FR-QR-001..004).
 *
 * Revoked codes are returned alongside the active one — that is the point of
 * revoking rather than deleting (FR-QR-007): the label history explains which
 * sticker was on the machine last term, and the scan logs attached to it still
 * resolve.
 *
 * The rendered SVG is attached by the controller under `meta`, not here: a list
 * of ten codes should not carry ten rendered images.
 *
 * @mixin QrCode
 */
class QrCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'payload' => $this->payload,
            'location_label' => $this->location_label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->value === 'active',
            'generated_at' => $this->generated_at?->toIso8601String(),
            'last_scanned_at' => $this->last_scanned_at?->toIso8601String(),
            'target_type' => $this->pc_unit_id !== null ? 'pc_unit' : 'asset',
        ];
    }
}
