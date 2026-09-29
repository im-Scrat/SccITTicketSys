<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\PcUnit;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The consolidated PC info view (SRS FR-PC-006): identity, network, specs,
 * installed hardware, QR, and warranty in one payload.
 *
 * FR-PC-006 also names ticket history and AI predictions. Full ticket
 * *history* arrives on the PC timeline (`AssetHistory::forPcUnit`) rather than
 * here, so the detail payload stays bounded; AI predictions are a Phase 3
 * concern and are absent by design rather than stubbed. `active_tickets`
 * (WP-G) is the one exception, added for the floor-plan PC inspector's "active
 * ticket" field: a bounded, *current-state* fact (is this machine's problem
 * still open right now?), unlike the unbounded history the timeline owns.
 *
 * @mixin PcUnit
 */
class PcUnitDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->room;
        $floor = $room?->floor;
        $building = $floor?->building;

        return [
            'id' => $this->uuid,
            'unit_code' => $this->unit_code,
            'pc_name' => $this->pc_name,
            'asset_tag' => $this->asset_tag,
            'hostname' => $this->hostname,
            'serial_number' => $this->serial_number,
            'brand' => $this->brand,
            'model' => $this->model,
            'notes' => $this->notes,

            'network' => [
                'ip_address' => $this->ip_address,
                'mac_address' => $this->mac_address,
            ],

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'condition' => $this->current_condition->value,
            'condition_label' => $this->current_condition->label(),

            'purchase' => [
                'date' => $this->purchase_date?->toIso8601String(),
            ],
            'warranty' => [
                'expiration' => $this->warranty_expiration?->toIso8601String(),
                'days_remaining' => $this->warrantyDaysRemaining(),
                'under_warranty' => $this->warranty_expiration !== null && ! $this->warranty_expiration->isPast(),
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

            'specification' => $this->specification !== null
                ? new PcSpecificationResource($this->specification)
                : null,

            'installations' => AssetInstallationResource::collection(
                $this->whenLoaded('componentInstallations')
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
            'active_tickets' => $this->whenLoaded(
                'tickets',
                fn () => $this->tickets
                    ->map(static fn (Ticket $ticket): array => [
                        'id' => $ticket->uuid,
                        'number' => $ticket->ticket_number,
                        'title' => $ticket->title,
                        'status' => $ticket->status?->name,
                        'priority' => $ticket->priority?->name,
                    ])
                    ->values()
                    ->all(),
                [],
            ),

            'qr_identifier' => $this->qr_identifier,
            'created_by' => $this->createdBy?->fullName(),
            'updated_by' => $this->updatedBy?->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
            'archived_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
