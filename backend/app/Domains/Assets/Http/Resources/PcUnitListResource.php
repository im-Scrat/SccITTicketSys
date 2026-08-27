<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\PcUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact PC-unit row for the directory table (SRS FR-PC-001).
 *
 * @mixin PcUnit
 */
class PcUnitListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->room;
        $building = $room?->floor?->building;

        return [
            'id' => $this->uuid,
            'unit_code' => $this->unit_code,
            'pc_name' => $this->pc_name,
            'asset_tag' => $this->asset_tag,
            'hostname' => $this->hostname,
            'serial_number' => $this->serial_number,
            'brand' => $this->brand,
            'model' => $this->model,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'condition' => $this->current_condition->value,
            'condition_label' => $this->current_condition->label(),

            'room' => $room !== null ? [
                'id' => $room->uuid,
                'name' => $room->name,
                'code' => $room->code,
                'archived' => $room->deleted_at !== null,
            ] : null,
            'building' => $building !== null ? [
                'id' => $building->uuid,
                'name' => $building->name,
                'code' => $building->code,
            ] : null,

            'components_count' => (int) ($this->component_installations_count ?? 0),
            'tickets_count' => (int) ($this->tickets_count ?? 0),

            'warranty_expiration' => $this->warranty_expiration?->toIso8601String(),
            'warranty_days_remaining' => $this->warrantyDaysRemaining(),

            'qr_identifier' => $this->qr_identifier,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
