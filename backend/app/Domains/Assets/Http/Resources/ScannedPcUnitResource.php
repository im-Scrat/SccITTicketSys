<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\MaintenanceChecklist;
use App\Models\MaintenanceRecord;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * **The scan-scoped PC panel** — the third asset projection (SRS FR-QR-012;
 * SDD DD-49, on the DD-41 pattern).
 *
 * Between the label-only lookup (`AssetOptionResource`) and the Administrator's
 * record ({@see PcUnitDetailResource}), and the only one a Technician can
 * reach. It carries what someone standing at a broken machine needs to do
 * authorized technical work, and it **has no field for anything else**.
 *
 * ── Why "has no field" and not "filters out" ───────────────────────────────
 *
 * DD-41 established the rule this class exists to follow: *a projection that
 * cannot represent a field is safer than a filter that strips it.* A filter is
 * one forgotten `unset()` away from leaking, and it leaks silently the day
 * someone adds a column upstream. There is deliberately no `$this->price`, no
 * `$this->supplier`, no `warranty` key anywhere below — so no upstream change
 * to `PcUnit`, `Asset` or their relations can push protected data through this
 * shape, whatever a future contributor does to the model.
 *
 * ── What is excluded, and by whose instruction ─────────────────────────────
 *
 * Client-restricted 2026-08-28 and reaffirmed in the WP-2.6b plan: **purchase
 * price, purchase date, supplier, procurement detail, any other financial
 * information, warranty terms, custodian records, unrestricted audit history,
 * archived rows, and every Asset Management edit affordance.** AC-QR-012 tests
 * their absence against the encoded payload.
 *
 * Additionally excluded under the approved field list (Client decision OD-7,
 * 2026-08-29): **serial number, asset tag, hostname, IP address and MAC
 * address.** These are register identifiers rather than repair data; the
 * technician identifies the machine by the `unit_code` printed on the same
 * label they just scanned. Excluding them is cheap to reverse and a leak is
 * not.
 *
 * `notes` is absent for the same reason — a free-text field on the equipment
 * register can contain anything an administrator put there, and nothing in
 * FR-QR-012's approved list asks for it.
 *
 * ── Scoping happens before this class, never inside it ─────────────────────
 *
 * The related ticket and maintenance collections arrive **already constrained**
 * by `TicketVisibility` and `MaintenanceVisibility`. A resource is a shape, not
 * a security boundary; deciding *which rows* inside a `toArray()` would put the
 * authorization decision in the one place nobody thinks to test.
 *
 * @mixin PcUnit
 */
class ScannedPcUnitResource extends JsonResource
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
            // Identity: what is printed on the label, and what a human calls it.
            'id' => $this->uuid,
            'unit_code' => $this->unit_code,
            'pc_name' => $this->pc_name,

            // Enough to know what kind of machine this is when sourcing a part.
            // Not register identity — no serial, tag, hostname or addresses.
            'brand' => $this->brand,
            'model' => $this->model,

            // "Its laboratory/location" — names only. No codes, no archived
            // flags, no room administration.
            'location' => $room !== null ? [
                'room' => $room->name,
                'floor' => $floor?->name,
                'building' => $building?->name,
            ] : null,

            // "Its condition and status".
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'condition' => $this->current_condition->value,
            'condition_label' => $this->current_condition->label(),

            // "The relevant technical specification".
            'specification' => $this->specification !== null
                ? new PcSpecificationResource($this->specification)
                : null,

            // "…and installed components." Deliberately a local shape rather
            // than AssetInstallationResource, which also carries `installed_by`
            // (a person) and archived flags. What the job needs is what is in
            // the box, not who put it there.
            'installed_components' => $this->whenLoaded(
                'componentInstallations',
                fn () => $this->componentInstallations
                    ->map(static fn (PcComponentInstallation $installation): array => [
                        'name' => $installation->asset?->displayName(),
                        'category' => $installation->asset?->hardwareModel?->component?->component_type?->value,
                        'category_label' => $installation->asset?->hardwareModel?->component?->component_type?->label(),
                        'installed_at' => $installation->installation_date?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                [],
            ),

            // "The relevant active ticket" — already scoped to this caller.
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

            // "The relevant active maintenance record and the repair
            // information that job needs; and the applicable checklist or work
            // instructions." Already scoped to this caller.
            'active_maintenance' => $this->whenLoaded(
                'maintenanceRecords',
                fn () => $this->maintenanceRecords
                    ->map(static fn (MaintenanceRecord $record): array => [
                        'id' => $record->uuid,
                        'title' => $record->title,
                        'type' => $record->type?->name,
                        'status' => $record->status->value,
                        'status_label' => $record->status->label(),
                        'scheduled_for' => $record->scheduled_for?->toIso8601String(),
                        'started_at' => $record->started_at?->toIso8601String(),

                        // The repair narrative the job needs.
                        'diagnosis' => $record->diagnosis,
                        'root_cause' => $record->root_cause,
                        'resolution' => $record->resolution,

                        'checklist' => $record->relationLoaded('checklists')
                            ? $record->checklists
                                ->map(static fn (MaintenanceChecklist $item): array => [
                                    'id' => (string) $item->getKey(),
                                    'label' => $item->item_label,
                                    'is_required' => (bool) $item->is_required,
                                    'is_completed' => (bool) $item->is_completed,
                                ])
                                ->values()
                            : [],
                    ])
                    ->values()
                    ->all(),
                [],
            ),

            // "QR status" — whether the label in the technician's hand is the
            // live one. Never the code itself: the caller already has it, and
            // echoing every code a machine ever carried would hand back the
            // revoked history for free.
            'qr' => $this->whenLoaded(
                'qrCodes',
                fn () => ($active = $this->qrCodes->first(
                    static fn (QrCode $code): bool => $code->status->value === 'active',
                )) !== null
                    ? [
                        'status' => $active->status->value,
                        'status_label' => $active->status->label(),
                        'location_label' => $active->location_label,
                    ]
                    : null,
                null,
            ),
        ];
    }
}
