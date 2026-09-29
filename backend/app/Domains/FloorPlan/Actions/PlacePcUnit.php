<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Actions;

use App\Domains\FloorPlan\Events\PositionUpdated;
use App\Domains\FloorPlan\Exceptions\FloorPlanRuleViolation;
use App\Domains\FloorPlan\Http\Resources\FloorPlanPcResource;
use App\Domains\FloorPlan\Services\CoordinateService;
use App\Domains\FloorPlan\Services\FloorPlanDefaults;
use App\Domains\FloorPlan\Services\RoomLayoutService;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Put a PC unit at a point on its room's active layout — a first placement or a
 * move (SRS FR-FP-003; the keyboard and numeric paths of FR-FP-009 arrive here
 * too, so there is one write path whatever the input device).
 *
 * **The server decides where the machine ends up.** The client sends the point
 * the user aimed at; this action rejects a point off the canvas, snaps it to the
 * layout's grid (unless free placement was asked for), clamps it, and stores
 * the result. The client's own snapping is a preview, and the response carries
 * the stored point so the client reconciles to it.
 *
 * Order of checks, and why:
 *
 *  1. **Authorization first**, through the policy abilities — never a
 *     `floorplan.*` permission string, which `Gate::before` would answer for a
 *     per-user grant (see `FloorPlanAccess`). `RoomLayoutService` authorizes
 *     again before each lookup, so an unauthorized caller learns nothing about
 *     which rooms, versions or machines exist.
 *  2. **Addressing** resolves the layout *through its room* and the machine
 *     *through the layout's room*, so a machine elsewhere in the estate is "not
 *     found" rather than found-and-refused. That scoping is also what stops this
 *     endpoint becoming an asset-transfer path: nothing here can change
 *     `pc_units.room_id`, and a unit outside the room never reaches the write.
 *  3. **Only the active layout** is editable; history is not rewritten.
 *  4. **Bounds** are checked on the point as sent. A point off the canvas is a
 *     client error (422), not something to quietly drag back inside — the
 *     `isWithinBounds` / `place` split in `CoordinateService` exists for this.
 *  5. **Under lock**, the layout, the machine and its current position row (if
 *     any) are re-read and re-checked. Between the lookups above and the
 *     write, another request could activate a newer layout, transfer the
 *     machine out of the room, or move it to a different spot; the row locks
 *     make every check and the write one decision. The layout lock also
 *     serializes every placement on this plan, which is what makes the "one
 *     machine per spot" check below race-free.
 *  6. **Optimistic concurrency (WP-F, D3).** A caller that has previously read
 *     this unit's position echoes its `updated_at` back as
 *     `$expectedUpdatedAt`. If the locked row's actual value has since moved —
 *     someone else placed this exact unit in the meantime — the write is
 *     refused with 409 and the *current* stored state, so the client
 *     reconciles in the same round trip. A caller with nothing to echo (a
 *     first placement) supplies null and the check is skipped; there is
 *     nothing yet to be stale against.
 *  7. **The audit row is written inside this transaction**, not after it —
 *     the same convention `TicketLifecycle`/`MaintenanceLifecycle` use for
 *     their own domain writes — so the position change and its history entry
 *     commit or roll back together. The broadcast in step 8 is a
 *     notification about an already-durable fact and stays outside.
 */
final class PlacePcUnit
{
    public function __construct(
        private readonly RoomLayoutService $layouts,
        private readonly CoordinateService $coordinates,
        private readonly FloorPlanDefaults $defaults,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  bool|null  $snap  snap to the layout's grid; null means the
     *                           `floor_plan.snap_to_grid` system default
     * @param  string|null  $expectedUpdatedAt  the `updated_at` this caller last saw
     *                                          for this unit's position (D3); null
     *                                          when there was nothing to have seen
     *
     * @throws AuthorizationException when the actor may not edit the floor plan
     * @throws ModelNotFoundException when the room, version or machine is not found
     * @throws FloorPlanRuleViolation when the layout is not active, the machine left
     *                                the room, the spot is taken, or the position is stale
     * @throws ValidationException when the point is off the canvas
     */
    public function handle(
        User $actor,
        string $roomUuid,
        int $version,
        string $pcUnitUuid,
        float $x,
        float $y,
        ?bool $snap = null,
        ?string $expectedUpdatedAt = null,
        ?Request $request = null,
    ): FloorPlanPosition {
        Gate::forUser($actor)->authorize('manage', FloorPlanPosition::class);

        $room = $this->layouts->room($actor, $roomUuid);
        $layout = $this->layouts->layout($actor, $room, $version);
        $this->layouts->assertEditable($layout);
        $pcUnit = $this->layouts->pcUnit($actor, $layout, $pcUnitUuid);

        $this->assertOnCanvas($layout, $x, $y);

        $point = ($snap ?? $this->defaults->snapToGrid())
            ? $this->coordinates->place($x, $y, $layout->width, $layout->height, $layout->grid_size)
            : [
                // Free placement still lands on storage precision and on the canvas.
                'x' => $this->coordinates->clamp($x, $layout->width),
                'y' => $this->coordinates->clamp($y, $layout->height),
            ];

        $position = DB::transaction(function () use ($layout, $pcUnit, $point, $expectedUpdatedAt, $actor, $request): FloorPlanPosition {
            /** @var RoomLayout $lockedLayout */
            $lockedLayout = RoomLayout::query()->whereKey($layout->getKey())->lockForUpdate()->firstOrFail();
            $this->layouts->assertEditable($lockedLayout);

            $lockedPc = PcUnit::query()->whereKey($pcUnit->getKey())->lockForUpdate()->first();

            if (! $lockedPc instanceof PcUnit) {
                // Archived between the lookup and the lock.
                throw (new ModelNotFoundException)->setModel(PcUnit::class);
            }

            $this->layouts->assertPcUnitInRoom($lockedLayout, $lockedPc);
            $this->assertSpotFree($lockedLayout, $lockedPc, $point);

            /** @var FloorPlanPosition|null $existing */
            $existing = FloorPlanPosition::query()
                ->where('room_layout_id', $lockedLayout->getKey())
                ->where('pc_unit_id', $lockedPc->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertNotStale($existing, $expectedUpdatedAt, $lockedPc);

            $before = $existing === null ? null : ['x' => (float) $existing->pos_x, 'y' => (float) $existing->pos_y];

            // `updated_at` is stamped with Postgres's own `clock_timestamp()`,
            // through the **plain query builder**, not an Eloquent attribute:
            //
            //  - Laravel's query grammar formats every *bound*
            //    `DateTimeInterface` parameter at `getDateFormat()` precision —
            //    whole seconds — when it prepares bindings
            //    (`Connection::prepareBindings()`), regardless of the column's
            //    own storage precision. That alone would silently defeat the
            //    D3 token for two writes inside the same second (the exact
            //    "two administrators, back to back" case D3 exists to catch),
            //    even after the column itself was widened to accept
            //    microseconds (see this package's migration).
            //  - A raw SQL expression bypasses that — but only if it is never
            //    assigned to an Eloquent *attribute* first: `created_at` and
            //    `updated_at` are date-cast, and Eloquent normalizes a date
            //    attribute through `asDateTime()` at the moment it is *set*
            //    (`fill()`/`setAttribute()`), not only when read — which
            //    rejects anything that is not itself already a date. Going
            //    through `DB::table(...)` avoids Eloquent's attribute layer
            //    entirely, so the raw expression reaches Postgres unexamined.
            $table = (new FloorPlanPosition)->getTable();

            if ($existing === null) {
                $id = DB::table($table)->insertGetId([
                    'room_layout_id' => $lockedLayout->getKey(),
                    'pc_unit_id' => $lockedPc->getKey(),
                    'pos_x' => $point['x'],
                    'pos_y' => $point['y'],
                    'rotation' => 0,
                    'z_index' => 0,
                    'created_at' => DB::raw('clock_timestamp()'),
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                $position = FloorPlanPosition::query()->whereKey($id)->firstOrFail();
                // A plain SELECT, not Eloquent's own insert path, so
                // `wasRecentlyCreated` defaults false even though a row was
                // genuinely just created — restated here so the controller's
                // `->response()` still answers 201, not 200, for a first
                // placement (unchanged, tested WP-D behaviour).
                $position->wasRecentlyCreated = true;
            } else {
                DB::table($table)->where('id', $existing->getKey())->update([
                    'pos_x' => $point['x'],
                    'pos_y' => $point['y'],
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                $position = $existing->fresh();
            }

            // WP-F — position history (SRS FR-FP-008; DD-55 stance: no dedicated
            // table). Subject is the PC unit, so this row surfaces on the
            // existing pc-unit audit timeline (AssetHistoryController) without
            // building a second one.
            $this->audit->activity(
                ActivityAction::AssetPositionChanged,
                actor: $actor,
                subject: $lockedPc,
                properties: [
                    'from' => $before,
                    'to' => $point,
                    'layout_version' => $lockedLayout->version,
                ],
                request: $request,
                module: 'floor-plan',
                description: sprintf(
                    '%s %s to (%s, %s) on layout v%d',
                    $lockedPc->pc_name,
                    $before === null ? 'placed' : 'moved',
                    $point['x'],
                    $point['y'],
                    $lockedLayout->version,
                ),
            );

            return $position->refresh()->setRelation('pcUnit', $lockedPc);
        });

        /*
         * WP-E — the notification seam (FR-FP-007). Outside the transaction and
         * after the write has actually committed, matching the project's
         * established convention (TicketLifecycle::transition dispatches its
         * TicketStatusChanged the same way): a subscriber must never be told
         * about a move that a concurrent failure then rolled back. The payload
         * is the same shape the HTTP response and the WP-C read endpoint use —
         * one resource, so a broadcast and a fetch can never disagree.
         */
        PositionUpdated::dispatch($room->uuid, $layout->version, (new FloorPlanPcResource($position))->resolve());

        return $position;
    }

    /**
     * WP-F, D3 — refuse a write whose caller's belief about the current state
     * no longer matches it.
     *
     * `$existing === null` means this is a first placement: nothing was ever
     * read, so nothing can be stale. `$expectedUpdatedAt === null` means the
     * caller had nothing to echo (the same case, from the client's side) and
     * is trusted — this is optimistic concurrency between cooperating
     * clients, not an authorization gate. Comparison is string-to-string at
     * `FloorPlanPcResource::UPDATED_AT_FORMAT` (microsecond) precision on both
     * sides — the exact precision the resource serializes, so a value that
     * round-tripped through JSON compares equal to itself.
     *
     * @throws FloorPlanRuleViolation
     */
    private function assertNotStale(?FloorPlanPosition $existing, ?string $expectedUpdatedAt, PcUnit $pcUnit): void
    {
        if ($existing === null || $expectedUpdatedAt === null) {
            return;
        }

        $actual = $existing->updated_at?->format(FloorPlanPcResource::UPDATED_AT_FORMAT);
        $expected = Carbon::parse($expectedUpdatedAt)->format(FloorPlanPcResource::UPDATED_AT_FORMAT);

        if ($actual !== $expected) {
            throw FloorPlanRuleViolation::staleWrite(
                (new FloorPlanPcResource($existing->setRelation('pcUnit', $pcUnit)))->resolve(),
            );
        }
    }

    /** The rejection test, applied to what the client actually sent. */
    private function assertOnCanvas(RoomLayout $layout, float $x, float $y): void
    {
        if ($this->coordinates->isWithinBounds($x, $y, $layout->width, $layout->height)) {
            return;
        }

        $errors = [];

        if (! is_finite($x) || $x < 0 || $x > $layout->width) {
            $errors['x'] = "The x position must be between 0 and {$layout->width}.";
        }

        if (! is_finite($y) || $y < 0 || $y > $layout->height) {
            $errors['y'] = "The y position must be between 0 and {$layout->height}.";
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * No other machine *that is still in this room* may stand on the point.
     *
     * A stale position row — its machine transferred away or archived — is not
     * drawn on the map (`FloorPlanService`), so it must not block the spot
     * either; otherwise an administrator would be refused a place that looks
     * empty.
     *
     * @param  array{x: float, y: float}  $point
     */
    private function assertSpotFree(RoomLayout $layout, PcUnit $pcUnit, array $point): void
    {
        $taken = FloorPlanPosition::query()
            ->where('room_layout_id', $layout->getKey())
            ->where('pc_unit_id', '<>', $pcUnit->getKey())
            ->where('pos_x', $point['x'])
            ->where('pos_y', $point['y'])
            ->whereHas('pcUnit', fn ($units) => $units->where('room_id', $layout->room_id))
            ->exists();

        if ($taken) {
            throw FloorPlanRuleViolation::positionOccupied();
        }
    }
}
