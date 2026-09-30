<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A PC unit's `status` changed while it is (or may be) shown on its room's
 * floor plan — WP-E; SRS FR-FP-007. Dispatched from wherever `pc_units.status`
 * is actually written: `UpdatePcUnit` (an administrator's direct edit) and
 * `MaintenanceLifecycle` (the automatic `under_maintenance` effect of
 * starting, completing or cancelling a visit).
 *
 * Broadcast on the machine's **current** room — the same room-scoping the map
 * itself uses — so a viewer of a different room's plan never learns that this
 * unit exists, let alone that its status moved.
 *
 * Position is deliberately absent: this event says nothing about *where* the
 * unit is, only what it now says on the map's node. A subscriber merges this
 * into whatever position (or unplaced entry) it already holds for the id.
 *
 * **After commit (WP-K).** `MaintenanceLifecycle` raises this from inside
 * `SubmitProofOfWork`'s outer transaction, where "after my own transaction"
 * is still uncommitted — so a map could have shown a status that then rolled
 * back. `ShouldDispatchAfterCommit` defers it to the root commit and drops it
 * on rollback, whoever the caller is.
 */
class PcStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{value: string, label: string, tone: string}  $status
     */
    public function __construct(
        public readonly string $roomUuid,
        public readonly string $pcUuid,
        public readonly string $name,
        public readonly string $unitCode,
        public readonly array $status,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("floor-plan.room.{$this->roomUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'floor-plan.pc-status-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'pc' => [
                'id' => $this->pcUuid,
                'name' => $this->name,
                'unit_code' => $this->unitCode,
                'status' => $this->status,
            ],
        ];
    }
}
