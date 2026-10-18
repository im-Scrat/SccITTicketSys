<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\AssetImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One import in the history list (Phase 2.10 R6): what it was, where it got
 * to, and the outcome in the finalizer's own words.
 *
 * @mixin AssetImport
 */
class AssetImportListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'file_name' => $this->original_filename,
            'entity_mode' => $this->entity_mode->value,
            'entity_mode_label' => $this->entity_mode->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'outcome' => $this->outcome?->value,
            'headline' => $this->result['headline'] ?? null,
            'tone' => $this->result['tone'] ?? null,
            'total_rows' => $this->total_rows,
            'created' => $this->result['created'] ?? null,
            'created_by' => $this->creator?->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
