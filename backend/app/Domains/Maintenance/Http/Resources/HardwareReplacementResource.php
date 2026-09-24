<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Models\HardwareReplacement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A part swapped during a maintenance visit (SRS FR-MNT-006; SDD DD-56).
 *
 * Four references, and they mean different things: the two `*_component` fields
 * name the **generic catalogue** part, while `old_asset`/`new_asset` name the
 * specific **serialized units** that physically moved. Either serialized side
 * may be null — a fan or a thermal pad is a real replacement that was never a
 * registered asset — which is exactly why `old_asset_id` had to be nullable.
 *
 * @mixin HardwareReplacement
 */
class HardwareReplacementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,
            'reason' => $this->replacement_reason,
            'warranty_months' => $this->warranty_months,
            'replaced_at' => $this->replaced_at?->toIso8601String(),

            'old_component' => $this->oldComponent !== null ? [
                'id' => $this->oldComponent->id,
                'label' => $this->oldComponent->name,
                'type' => $this->oldComponent->component_type->value,
            ] : null,
            'new_component' => $this->newComponent !== null ? [
                'id' => $this->newComponent->id,
                'label' => $this->newComponent->name,
                'type' => $this->newComponent->component_type->value,
            ] : null,

            'old_asset' => $this->oldAsset !== null ? [
                'id' => $this->oldAsset->uuid,
                'label' => $this->oldAsset->displayName(),
                'asset_tag' => $this->oldAsset->asset_tag,
            ] : null,
            'new_asset' => $this->newAsset !== null ? [
                'id' => $this->newAsset->uuid,
                'label' => $this->newAsset->displayName(),
                'asset_tag' => $this->newAsset->asset_tag,
            ] : null,
        ];
    }
}
