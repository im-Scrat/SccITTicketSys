<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\Asset;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One installed-component link (SRS FR-PC-004) — the authoritative record of
 * what is physically inside a machine, as opposed to the editable spec snapshot.
 *
 * Both directions are served from this one resource: on a PC's detail page it
 * reads "what is fitted in me", and on an asset's page it reads "which machine
 * am I in". Whichever side is loaded is the side that renders.
 *
 * @mixin PcComponentInstallation
 */
class AssetInstallationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $asset = $this->whenLoaded('asset') instanceof Asset ? $this->asset : null;
        $pcUnit = $this->whenLoaded('pcUnit') instanceof PcUnit ? $this->pcUnit : null;

        return [
            'id' => (string) $this->getKey(),
            'installation_status' => $this->installation_status->value,
            'installation_status_label' => $this->installation_status->label(),
            'installed_at' => $this->installation_date?->toIso8601String(),
            'removed_at' => $this->removal_date?->toIso8601String(),
            'current' => $this->removal_date === null,
            'remarks' => $this->remarks,
            'installed_by' => $this->installedBy?->fullName(),

            'asset' => $asset !== null ? [
                'id' => $asset->uuid,
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->displayName(),
                'category' => $asset->hardwareModel?->component?->component_type?->value,
                'category_label' => $asset->hardwareModel?->component?->component_type?->label(),
                'archived' => $asset->deleted_at !== null,
            ] : null,

            'pc_unit' => $pcUnit !== null ? [
                'id' => $pcUnit->uuid,
                'unit_code' => $pcUnit->unit_code,
                'pc_name' => $pcUnit->pc_name,
                'archived' => $pcUnit->deleted_at !== null,
            ] : null,
        ];
    }
}
