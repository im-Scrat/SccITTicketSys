<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A floor-plan invariant refused an otherwise-authorized request.
 *
 * Self-rendering, following `LocationInUseException`: a machine-readable `code`
 * so the client can react without parsing prose. These are *business-rule*
 * refusals (422/409), raised only after authorization has succeeded — an
 * unauthorized caller gets a 403 first and never learns which rule they would
 * have hit.
 */
class FloorPlanRuleViolation extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        /** @var array<string, mixed> */
        private readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Placing a PC on a plan it does not belong to would re-home it across
     * rooms — an asset transfer that bypasses `assets.transfer`.
     */
    public static function pcUnitNotInRoom(): self
    {
        return new self(
            'That PC unit does not belong to this room.',
            'pc_unit_not_in_room',
            422,
        );
    }

    /** Only a room's active layout is editable; older versions are history. */
    public static function layoutNotActive(): self
    {
        return new self(
            'Only the active layout of a room can be changed.',
            'layout_not_active',
            409,
        );
    }

    /**
     * Two machines cannot stand on the same spot: the second would be drawn
     * exactly over the first and vanish from the map, and from the keyboard
     * user's list of focusable units it would read as a duplicate.
     */
    public static function positionOccupied(): self
    {
        return new self(
            'Another PC unit already stands at that position.',
            'position_occupied',
            422,
        );
    }

    /**
     * WP-F — optimistic concurrency (D3). The position this actor loaded no
     * longer matches what is stored: someone else moved this unit since. The
     * refusal carries the **current** stored state, in the identical shape a
     * successful placement returns, so the client can reconcile to it in the
     * same round trip rather than issuing a second GET.
     *
     * @param  array<string, mixed>  $current
     */
    public static function staleWrite(array $current): self
    {
        return new self(
            'This unit was moved by someone else since the plan was loaded.',
            'position_stale',
            409,
            ['current' => $current],
        );
    }

    /**
     * Phase 2.11 (DD-101) — the whole-layout counterpart of `staleWrite`.
     * Someone saved this room's plan (or a machine left the room) since the
     * editor loaded it. The refusal carries the **current** plan, in the same
     * shape the GET returns, so the editor can merge object by object in the
     * same round trip instead of throwing the administrator's draft away.
     *
     * @param  array<string, mixed>  $current
     */
    public static function layoutStale(array $current): self
    {
        return new self(
            'This Floorplan was saved by someone else since you opened it.',
            'layout_stale',
            409,
            ['current' => $current],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$this->extra,
        ], $this->status);
    }
}
