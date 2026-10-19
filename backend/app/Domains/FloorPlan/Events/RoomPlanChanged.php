<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A room's Floorplan changed — Phase 2.11 (SDD DD-101); SRS FR-FP-007.
 *
 * Deliberately a *notice*, not the new plan: a viewer refetches the plan
 * through the authorized GET, so a broadcast and a fetch can never disagree
 * about its content, and nothing a viewer may not read can leak through the
 * socket. The editor uses `revision` to decide what to do — a clean editor
 * refetches and announces it, a dirty one keeps its draft and offers to
 * combine (`merge3`).
 *
 * `cause` says why: `layout_saved`, `layout_created`, `layout_activated`, or
 * `roster` (a machine joined or left the room, so the unplaced list moved —
 * `revision` only advances when a placed machine's position was released).
 *
 * `by` carries a display **name only** — never an id or an email — so a
 * banner can say "Ana saved this room" (NFR-SEC-001).
 *
 * Dispatched after commit: a viewer is never told about a save that then
 * rolled back, whoever owns the outermost transaction.
 */
class RoomPlanChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $roomUuid,
        public readonly ?int $layoutVersion,
        public readonly ?int $revision,
        public readonly string $cause,
        public readonly ?string $by = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("floor-plan.room.{$this->roomUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'floor-plan.room-plan-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'layout_version' => $this->layoutVersion,
            'revision' => $this->revision,
            'cause' => $this->cause,
            'by' => $this->by === null ? null : ['name' => $this->by],
        ];
    }
}
