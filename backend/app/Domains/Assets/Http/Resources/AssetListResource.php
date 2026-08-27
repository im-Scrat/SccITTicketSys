<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact asset row for the directory table (SRS FR-AST-011).
 *
 * Carries just enough context to be readable without opening the record: what it
 * is, where it is, who holds it, and how its warranty stands. `id` is always the
 * uuid — a numeric key never leaves the server (NFR-SEC-001, SDD DD-15).
 *
 * @mixin Asset
 */
class AssetListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $model = $this->hardwareModel;
        $component = $model?->component;
        $room = $this->currentRoom;
        $building = $room?->floor?->building;

        return [
            'id' => $this->uuid,
            'asset_tag' => $this->asset_tag,
            'name' => $this->displayName(),
            'serial_number' => $this->serial_number,
            'barcode' => $this->barcode,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tone' => $this->status->tone(),
            'condition' => $this->condition->value,
            'condition_label' => $this->condition->label(),

            'category' => $component?->component_type?->value,
            'category_label' => $component?->component_type?->label(),
            'manufacturer' => $component?->manufacturer?->name,
            'model' => $model?->model_name,

            'supplier' => $this->whenLoaded('supplier', fn (): ?string => $this->supplier?->name),

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

            'technician' => $this->assignedTechnician !== null ? [
                'id' => $this->assignedTechnician->uuid,
                'name' => $this->assignedTechnician->fullName(),
            ] : null,

            'warranty_expiration' => $this->warranty_expiration?->toIso8601String(),
            'warranty_days_remaining' => $this->warrantyDaysRemaining(),
            'under_warranty' => $this->underWarranty(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
