<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full asset record behind the detail page (SRS FR-AST-002, FR-PC-006).
 *
 * This is the payload the ten-tab management page is built from, so it carries
 * the record's own attributes plus the *shape* of everything hanging off it —
 * installations, transfers, attachments, QR — while the heavier collections
 * (timeline, maintenance) are paginated on their own endpoints.
 *
 * `warranty` is grouped rather than flattened because the Warranty tab reads it
 * as one thing, and `days_remaining` is computed server-side so client and
 * server never disagree about what "expiring soon" means.
 *
 * @mixin Asset
 */
class AssetDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $model = $this->hardwareModel;
        $component = $model?->component;
        $room = $this->currentRoom;
        $floor = $room?->floor;
        $building = $floor?->building;

        return [
            'id' => $this->uuid,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'serial_number' => $this->serial_number,
            'barcode' => $this->barcode,
            'notes' => $this->notes,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tone' => $this->status->tone(),
            'terminal' => $this->status->isTerminal(),
            'condition' => $this->condition->value,
            'condition_label' => $this->condition->label(),

            'category' => $component?->component_type?->value,
            'category_label' => $component?->component_type?->label(),
            'category_group' => $component?->component_type?->group(),
            'manufacturer' => $component?->manufacturer?->name,
            'component' => $component?->name,
            'model' => $model !== null ? [
                'id' => $model->getKey(),
                'name' => $model->model_name,
                'number' => $model->model_number,
                'specifications' => $model->specifications,
            ] : null,

            'supplier' => $this->supplier !== null ? [
                'name' => $this->supplier->name,
                'contact_person' => $this->supplier->contact_person,
                'contact_number' => $this->supplier->contact_number,
                'email' => $this->supplier->email,
            ] : null,

            'purchase' => [
                'price' => $this->purchase_price,
                'date' => $this->purchase_date?->toIso8601String(),
            ],

            'warranty' => [
                'expiration' => $this->warranty_expiration?->toIso8601String(),
                'days_remaining' => $this->warrantyDaysRemaining(),
                'under_warranty' => $this->underWarranty(),
                'supplier' => $this->supplier?->name,
                'purchase_date' => $this->purchase_date?->toIso8601String(),
            ],

            'room' => $room !== null ? [
                'id' => $room->uuid,
                'name' => $room->name,
                'code' => $room->code,
                'archived' => $room->deleted_at !== null,
            ] : null,
            'floor' => $floor !== null ? [
                'id' => $floor->uuid,
                'name' => $floor->name,
                'floor_number' => (int) $floor->floor_number,
            ] : null,
            'building' => $building !== null ? [
                'id' => $building->uuid,
                'name' => $building->name,
                'code' => $building->code,
            ] : null,

            'technician' => $this->assignedTechnician !== null ? [
                'id' => $this->assignedTechnician->uuid,
                'name' => $this->assignedTechnician->fullName(),
                'email' => $this->assignedTechnician->email,
            ] : null,

            'installations' => AssetInstallationResource::collection(
                $this->whenLoaded('installations')
            ),
            'transfers' => AssetTransferResource::collection(
                $this->whenLoaded('transfers')
            ),
            'attachments' => AssetAttachmentResource::collection(
                $this->whenLoaded('attachments')
            ),
            'qr_codes' => QrCodeResource::collection(
                $this->whenLoaded('qrCodes')
            ),
            'maintenance' => MaintenanceSummaryResource::collection(
                $this->whenLoaded('maintenanceRecords')
            ),

            'created_by' => $this->createdBy?->fullName(),
            'updated_by' => $this->updatedBy?->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
            'archived_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
