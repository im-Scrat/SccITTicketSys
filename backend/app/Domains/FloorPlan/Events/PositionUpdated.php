<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A PC unit's stored position changed on a room's active layout (WP-E; SRS
 * FR-FP-007). Dispatched by `PlacePcUnit` after its write transaction commits.
 *
 * **The payload is built at dispatch time, not carried as a model.** The
 * caller has already loaded the position and its PC unit; converting to a
 * plain array immediately means the queued job carries exactly the fields
 * that go out over the wire, with no risk of a later `broadcastWith()` change
 * accidentally widening what a re-fetched model would expose. The shape is
 * the same narrow one `FloorPlanPcResource` renders for the read endpoint, so
 * a client reconciles a broadcast exactly as it would a fetch response.
 *
 * No numeric id, no serial number, no network address, no actor identity —
 * only what every subscriber of this room's channel is already authorized to
 * see on the map itself.
 */
class PositionUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{id: string, name: string, unit_code: string, status: array{value: string, label: string, tone: string}, x: float, y: float, rotation: float, z_index: int}  $pc
     */
    public function __construct(
        public readonly string $roomUuid,
        public readonly int $layoutVersion,
        public readonly array $pc,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("floor-plan.room.{$this->roomUuid}")];
    }

    /** A stable, opaque wire name — never the class's FQCN. */
    public function broadcastAs(): string
    {
        return 'floor-plan.position-updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'layout_version' => $this->layoutVersion,
            'pc' => $this->pc,
        ];
    }
}
