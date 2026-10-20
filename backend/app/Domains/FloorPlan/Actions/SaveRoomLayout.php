<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Actions;

use App\Domains\FloorPlan\DTOs\RoomPlan;
use App\Domains\FloorPlan\Events\RoomPlanChanged;
use App\Domains\FloorPlan\Exceptions\FloorPlanRuleViolation;
use App\Domains\FloorPlan\Http\Resources\RoomPlanResource;
use App\Domains\FloorPlan\Services\FloorPlanService;
use App\Domains\FloorPlan\Services\LayoutGeometry;
use App\Domains\FloorPlan\Services\RoomLayoutService;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\FloorPlanAssetPosition;
use App\Models\FloorPlanFixture;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Save a room's whole Floorplan in one transaction (Phase 2.11, SDD DD-101;
 * SRS FR-FP-001/003/008). The editor collects changes as a draft — with undo
 * and redo — and sends the complete document here when the administrator
 * saves: every placed workstation, every fixture, and the layout's size and
 * grid. This is now the *only* write path for positions; the per-machine
 * PATCH it replaces is gone, so there is one rule set (DD-101 D5).
 *
 * Order of checks, and why:
 *
 *  1. **Authorization first**, through the policy ability (`manage` on
 *     RoomLayout), never a `floorplan.*` string — `Gate::before` would answer
 *     that for a per-user grant. `RoomLayoutService` authorizes again before
 *     each lookup, so an unauthorized caller learns nothing about which rooms
 *     or versions exist.
 *  2. **Only the active layout** is editable; older versions are history.
 *  3. **Geometry** (`LayoutGeometry`) needs only the payload, so it is judged
 *     before any lock: every shape inside the room — measured against the
 *     *new* size, so a resize that would strand something is refused — and no
 *     two workstations overlapping. Errors are keyed by payload index
 *     (`workstations.3.x`), which is how the editor finds the object to mark.
 *  4. **Under the layout row lock**, the revision is compared with the one the
 *     editor loaded. A mismatch is refused with 409 `layout_stale` and the
 *     *current* plan, so the editor can merge object by object in the same
 *     round trip. Only the layout row is locked: machines are read, not
 *     locked, which avoids a lock-order deadlock with a room change
 *     (`UpdatePcUnit` locks the machine, then the layout, through
 *     `ReleasePcUnitPositions`). A room change that commits after this save
 *     releases the position it no longer owns and bumps the revision, so the
 *     end state is consistent whichever transaction wins.
 *  5. **Every machine must live in this room.** Placing a machine here never
 *     moves it here — that is an asset room change, with its own permission
 *     (`assets.update`) and audit — so a machine elsewhere is refused.
 *
 * The coordinates stored are the ones the editor drew (rounded to storage
 * precision): snapping is an editing aid the administrator can switch off, so
 * the server validates positions rather than re-snapping them (DD-101 D6).
 *
 * **Equipment** (DD-115) — assets a custodian confirmed are in this room —
 * travels in an optional `equipment` list with the same footprint and rules
 * as a workstation: inside the room, overlapping nothing, and only assets that
 * are in this room and confirmed there. A save that omits the list (an older
 * client) leaves every equipment position as it is.
 *
 * The audit rows are written inside the transaction — one
 * `AssetPositionChanged` / `AssetPositionCleared` per machine whose place
 * changed (it surfaces on that PC's own history), and one `LayoutUpdated`
 * summary on the layout. The broadcast is a notice about a committed fact and
 * is dispatched after commit.
 */
final class SaveRoomLayout
{
    public function __construct(
        private readonly RoomLayoutService $layouts,
        private readonly FloorPlanService $plans,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{width: int|string, height: int|string, grid_size: int|string}  $settings
     * @param  list<array{pc_unit: string, x: float|int|string, y: float|int|string, rotation: int|string}>  $workstations
     * @param  list<array{id: string, kind: string, x: float|int|string, y: float|int|string, width: float|int|string, height: float|int|string, rotation: float|int|string, label?: string|null}>  $fixtures
     * @param  list<array{asset: string, x: float|int|string, y: float|int|string, rotation: int|string}>|null  $equipment  null keeps every equipment position
     *
     * @throws AuthorizationException when the actor may not edit the Floorplan
     * @throws ModelNotFoundException when the room or version does not exist
     * @throws FloorPlanRuleViolation when the layout is not active or is stale
     * @throws ValidationException when a shape breaks the geometry rules or a machine is not in the room
     */
    public function handle(
        User $actor,
        string $roomUuid,
        int $version,
        int $expectedRevision,
        array $settings,
        array $workstations,
        array $fixtures,
        ?Request $request = null,
        ?array $equipment = null,
    ): RoomPlan {
        $this->layouts->authorizeManage($actor);
        $room = $this->layouts->room($actor, $roomUuid);
        $layout = $this->layouts->layout($actor, $room, $version);
        $this->layouts->assertEditable($layout);

        $size = [
            'width' => (int) $settings['width'],
            'height' => (int) $settings['height'],
            'grid_size' => (int) $settings['grid_size'],
        ];
        $placed = array_map(static fn (array $w): array => [
            'pc_unit' => Str::lower($w['pc_unit']),
            'x' => round((float) $w['x'], 2),
            'y' => round((float) $w['y'], 2),
            'rotation' => (int) $w['rotation'],
        ], $workstations);
        $shapes = array_map(static fn (array $f): array => [
            'uuid' => Str::lower($f['id']),
            'kind' => $f['kind'],
            'pos_x' => round((float) $f['x'], 2),
            'pos_y' => round((float) $f['y'], 2),
            'width' => round((float) $f['width'], 2),
            'height' => round((float) $f['height'], 2),
            'rotation' => round((float) $f['rotation'], 2),
            'label' => isset($f['label']) && trim((string) $f['label']) !== '' ? trim((string) $f['label']) : null,
        ], $fixtures);

        $items = $equipment === null ? null : array_map(static fn (array $e): array => [
            'asset' => Str::lower($e['asset']),
            'x' => round((float) $e['x'], 2),
            'y' => round((float) $e['y'], 2),
            'rotation' => (int) $e['rotation'],
        ], $equipment);

        $this->assertGeometry($size, $placed, $shapes, $items ?? []);

        $saved = DB::transaction(function () use ($actor, $room, $layout, $roomUuid, $expectedRevision, $size, $placed, $shapes, $items, $request): ?RoomLayout {
            /** @var RoomLayout $locked */
            $locked = RoomLayout::query()->whereKey($layout->getKey())->lockForUpdate()->firstOrFail();
            $this->layouts->assertEditable($locked);

            if ($locked->revision !== $expectedRevision) {
                throw FloorPlanRuleViolation::layoutStale(
                    (new RoomPlanResource($this->plans->roomPlan($actor, $roomUuid)))->resolve($request),
                );
            }

            $units = $this->machinesInRoom($room, $placed);
            $positions = $this->applyPositions($actor, $locked, $room, $units, $placed, $request);
            $fixtureChanges = $this->applyFixtures($locked, $shapes);
            $equipmentChanges = $items === null
                ? ['placed' => 0, 'moved' => 0, 'removed' => 0]
                : $this->applyEquipment($actor, $locked, $this->equipmentInRoom($room, $items), $items, $request);
            $settingsChanged = $locked->width !== $size['width']
                || $locked->height !== $size['height']
                || $locked->grid_size !== $size['grid_size'];

            if (array_sum($positions) + array_sum($fixtureChanges) + array_sum($equipmentChanges) === 0 && ! $settingsChanged) {
                // Nothing differs from what is stored: no new revision, so a
                // no-op save never makes another administrator's draft stale.
                return null;
            }

            $from = ['width' => $locked->width, 'height' => $locked->height, 'grid_size' => $locked->grid_size];

            $locked->forceFill([
                ...$size,
                'revision' => $locked->revision + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->activity(
                ActivityAction::LayoutUpdated,
                actor: $actor,
                subject: $locked,
                properties: array_filter([
                    'version' => $locked->version,
                    'revision' => $locked->revision,
                    'workstations' => $positions,
                    'fixtures' => $fixtureChanges,
                    'equipment' => $items === null ? null : $equipmentChanges,
                    'settings' => $settingsChanged ? ['from' => $from, 'to' => $size] : null,
                ], static fn (mixed $value): bool => $value !== null),
                request: $request,
                module: 'floor-plan',
                description: "Floorplan of {$room->name} saved (layout v{$locked->version}, revision {$locked->revision})",
            );

            return $locked;
        });

        if ($saved !== null) {
            RoomPlanChanged::dispatch($room->uuid, $saved->version, $saved->revision, 'layout_saved', $actor->fullName());
        }

        return $this->plans->roomPlan($actor, $roomUuid);
    }

    /**
     * @param  array{width: int, height: int, grid_size: int}  $size
     * @param  list<array{pc_unit: string, x: float, y: float, rotation: int}>  $placed
     * @param  list<array{uuid: string, kind: string, pos_x: float, pos_y: float, width: float, height: float, rotation: float, label: string|null}>  $shapes
     * @param  list<array{asset: string, x: float, y: float, rotation: int}>  $items
     *
     * @throws ValidationException
     */
    private function assertGeometry(array $size, array $placed, array $shapes, array $items = []): void
    {
        $errors = [];
        // Workstations and equipment share one footprint and one "no overlap"
        // rule, keyed by the payload path the editor marks.
        $boxes = [];

        foreach ($placed as $i => $w) {
            $boxes["workstations.{$i}.x"] = LayoutGeometry::workstationBox($w['x'], $w['y']);
        }

        foreach ($items as $i => $e) {
            $boxes["equipment.{$i}.x"] = LayoutGeometry::workstationBox($e['x'], $e['y']);
        }

        foreach ($boxes as $key => $box) {
            if (! LayoutGeometry::boxInsideRoom($box, $size['width'], $size['height'])) {
                $errors[$key] = self::noun($key).' does not fit inside the room at that position.';
            }
        }

        $keys = array_keys($boxes);

        foreach ($keys as $a => $i) {
            foreach (array_slice($keys, $a + 1) as $j) {
                if (! LayoutGeometry::boxesOverlap($boxes[$i], $boxes[$j])) {
                    continue;
                }

                $errors[$i] ??= self::noun($i).' overlaps another one.';
                $errors[$j] ??= self::noun($j).' overlaps another one.';
            }
        }

        foreach ($shapes as $i => $f) {
            $corners = LayoutGeometry::corners($f['pos_x'], $f['pos_y'], $f['width'], $f['height'], $f['rotation']);

            if (! LayoutGeometry::pointsInsideRoom($corners, $size['width'], $size['height'])) {
                $errors["fixtures.{$i}.x"] = 'This '.Str::lower($f['kind']).' does not fit inside the room at that position.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The live machines this room holds, for the uuids the editor placed.
     *
     * @param  list<array{pc_unit: string, x: float, y: float, rotation: int}>  $placed
     * @return array<string, PcUnit> keyed by uuid
     *
     * @throws ValidationException
     */
    private function machinesInRoom(Room $room, array $placed): array
    {
        $units = PcUnit::query()
            ->where('room_id', $room->getKey())
            ->whereIn('uuid', array_column($placed, 'pc_unit'))
            ->get()
            ->keyBy('uuid')
            ->all();

        $errors = [];

        foreach ($placed as $i => $w) {
            if (! isset($units[$w['pc_unit']])) {
                $errors["workstations.{$i}.pc_unit"] = 'This PC unit is no longer in this room.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $units;
    }

    /**
     * Insert, move and remove positions so the layout holds exactly what was
     * sent. Rows for machines that have since left the room are not this
     * save's to touch: they are history, never drawn (`FloorPlanService`).
     *
     * `updated_at` is stamped with Postgres's `clock_timestamp()` through the
     * plain query builder, the precedent WP-F set for this column (microsecond
     * precision survives; Eloquent would bind it at whole seconds).
     *
     * @param  array<string, PcUnit>  $units
     * @param  list<array{pc_unit: string, x: float, y: float, rotation: int}>  $placed
     * @return array{placed: int, moved: int, removed: int}
     */
    private function applyPositions(User $actor, RoomLayout $layout, Room $room, array $units, array $placed, ?Request $request): array
    {
        $existing = FloorPlanPosition::query()
            ->where('room_layout_id', $layout->getKey())
            ->whereHas('pcUnit', fn ($q) => $q->where('room_id', $room->getKey()))
            ->with('pcUnit')
            ->lockForUpdate()
            ->get()
            ->keyBy('pc_unit_id');

        $table = (new FloorPlanPosition)->getTable();
        $counts = ['placed' => 0, 'moved' => 0, 'removed' => 0];
        $kept = [];

        foreach ($placed as $w) {
            $unit = $units[$w['pc_unit']];
            $kept[] = $unit->getKey();
            /** @var FloorPlanPosition|null $old */
            $old = $existing->get($unit->getKey());
            $to = ['x' => $w['x'], 'y' => $w['y'], 'rotation' => $w['rotation']];

            if ($old === null) {
                DB::table($table)->insert([
                    'room_layout_id' => $layout->getKey(),
                    'pc_unit_id' => $unit->getKey(),
                    'pos_x' => $w['x'],
                    'pos_y' => $w['y'],
                    'rotation' => $w['rotation'],
                    'z_index' => 0,
                    'created_at' => DB::raw('clock_timestamp()'),
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                $counts['placed']++;
                $this->auditPosition($actor, $unit, $layout, null, $to, $request);

                continue;
            }

            $from = ['x' => (float) $old->pos_x, 'y' => (float) $old->pos_y, 'rotation' => (int) round((float) $old->rotation)];

            if ($from === $to) {
                continue;
            }

            DB::table($table)->where('id', $old->getKey())->update([
                'pos_x' => $w['x'],
                'pos_y' => $w['y'],
                'rotation' => $w['rotation'],
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $counts['moved']++;
            $this->auditPosition($actor, $unit, $layout, $from, $to, $request);
        }

        foreach ($existing as $pcUnitId => $old) {
            if (in_array($pcUnitId, $kept, true)) {
                continue;
            }

            DB::table($table)->where('id', $old->getKey())->delete();
            $counts['removed']++;

            $this->audit->activity(
                ActivityAction::AssetPositionCleared,
                actor: $actor,
                subject: $old->pcUnit,
                properties: [
                    'from' => ['x' => (float) $old->pos_x, 'y' => (float) $old->pos_y, 'rotation' => (int) round((float) $old->rotation)],
                    'layout_version' => $layout->version,
                    'reason' => 'removed_from_plan',
                ],
                request: $request,
                module: 'floor-plan',
                description: "{$old->pcUnit->pc_name} removed from the Floorplan (layout v{$layout->version})",
            );
        }

        return $counts;
    }

    /**
     * @param  array{x: float, y: float, rotation: int}|null  $from
     * @param  array{x: float, y: float, rotation: int}  $to
     */
    private function auditPosition(User $actor, PcUnit $unit, RoomLayout $layout, ?array $from, array $to, ?Request $request): void
    {
        $this->audit->activity(
            ActivityAction::AssetPositionChanged,
            actor: $actor,
            subject: $unit,
            properties: ['from' => $from, 'to' => $to, 'layout_version' => $layout->version],
            request: $request,
            module: 'floor-plan',
            description: sprintf(
                '%s %s to (%s, %s) on layout v%d',
                $unit->pc_name,
                $from === null ? 'placed' : 'moved',
                $to['x'],
                $to['y'],
                $layout->version,
            ),
        );
    }

    /** "This workstation" or "This equipment", from a payload error key. */
    private static function noun(string $key): string
    {
        return str_starts_with($key, 'equipment') ? 'This equipment' : 'This workstation';
    }

    /**
     * The confirmed assets this room holds, for the uuids the editor placed
     * (DD-115). An asset elsewhere, archived, or not confirmed here is refused
     * — placing equipment never moves it, exactly as for a PC.
     *
     * @param  list<array{asset: string, x: float, y: float, rotation: int}>  $items
     * @return array<string, Asset> keyed by uuid
     *
     * @throws ValidationException
     */
    private function equipmentInRoom(Room $room, array $items): array
    {
        $assets = Asset::query()
            ->where('current_room_id', $room->getKey())
            ->whereNotNull('placement_confirmed_at')
            ->whereIn('uuid', array_column($items, 'asset'))
            ->get()
            ->keyBy('uuid')
            ->all();

        $errors = [];

        foreach ($items as $i => $e) {
            if (! isset($assets[$e['asset']])) {
                $errors["equipment.{$i}.asset"] = 'This equipment is no longer confirmed in this room.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $assets;
    }

    /**
     * Insert, move and remove equipment positions so the layout holds exactly
     * what was sent — the equipment counterpart of {@see applyPositions()}, with
     * the same per-item audit on the asset's own history.
     *
     * @param  array<string, Asset>  $assets
     * @param  list<array{asset: string, x: float, y: float, rotation: int}>  $items
     * @return array{placed: int, moved: int, removed: int}
     */
    private function applyEquipment(User $actor, RoomLayout $layout, array $assets, array $items, ?Request $request): array
    {
        // As for PCs: rows for equipment that has left the room (or was
        // archived) are history, not this save's to touch.
        $existing = FloorPlanAssetPosition::query()
            ->where('room_layout_id', $layout->getKey())
            ->whereHas('asset', fn ($q) => $q->where('current_room_id', $layout->room_id)->whereNotNull('placement_confirmed_at'))
            ->with('asset')
            ->lockForUpdate()
            ->get()
            ->keyBy('asset_id');

        $table = (new FloorPlanAssetPosition)->getTable();
        $counts = ['placed' => 0, 'moved' => 0, 'removed' => 0];
        $kept = [];

        foreach ($items as $e) {
            $asset = $assets[$e['asset']];
            $kept[] = $asset->getKey();
            /** @var FloorPlanAssetPosition|null $old */
            $old = $existing->get($asset->getKey());
            $to = ['x' => $e['x'], 'y' => $e['y'], 'rotation' => $e['rotation']];

            if ($old === null) {
                DB::table($table)->insert([
                    'room_layout_id' => $layout->getKey(),
                    'asset_id' => $asset->getKey(),
                    'pos_x' => $e['x'],
                    'pos_y' => $e['y'],
                    'rotation' => $e['rotation'],
                    'z_index' => 0,
                    'created_at' => DB::raw('clock_timestamp()'),
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                $counts['placed']++;
                $this->auditEquipment($actor, $asset, $layout, null, $to, $request);

                continue;
            }

            $from = ['x' => (float) $old->pos_x, 'y' => (float) $old->pos_y, 'rotation' => (int) round((float) $old->rotation)];

            if ($from === $to) {
                continue;
            }

            DB::table($table)->where('id', $old->getKey())->update([
                'pos_x' => $e['x'],
                'pos_y' => $e['y'],
                'rotation' => $e['rotation'],
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $counts['moved']++;
            $this->auditEquipment($actor, $asset, $layout, $from, $to, $request);
        }

        foreach ($existing as $assetId => $old) {
            if (in_array($assetId, $kept, true)) {
                continue;
            }

            DB::table($table)->where('id', $old->getKey())->delete();
            $counts['removed']++;

            $this->audit->activity(
                ActivityAction::AssetPositionCleared,
                actor: $actor,
                subject: $old->asset,
                properties: [
                    'from' => ['x' => (float) $old->pos_x, 'y' => (float) $old->pos_y, 'rotation' => (int) round((float) $old->rotation)],
                    'layout_version' => $layout->version,
                    'reason' => 'removed_from_plan',
                ],
                request: $request,
                module: 'floor-plan',
                description: "{$old->asset->displayName()} removed from the Floorplan (layout v{$layout->version})",
            );
        }

        return $counts;
    }

    /**
     * @param  array{x: float, y: float, rotation: int}|null  $from
     * @param  array{x: float, y: float, rotation: int}  $to
     */
    private function auditEquipment(User $actor, Asset $asset, RoomLayout $layout, ?array $from, array $to, ?Request $request): void
    {
        $this->audit->activity(
            ActivityAction::AssetPositionChanged,
            actor: $actor,
            subject: $asset,
            properties: ['from' => $from, 'to' => $to, 'layout_version' => $layout->version],
            request: $request,
            module: 'floor-plan',
            description: sprintf(
                '%s %s to (%s, %s) on layout v%d',
                $asset->displayName(),
                $from === null ? 'placed' : 'moved',
                $to['x'],
                $to['y'],
                $layout->version,
            ),
        );
    }

    /**
     * Replace the layout's fixtures with the ones sent. Nothing references a
     * fixture, so a delete-and-insert is exact and cheap; the counts are only
     * for the summary audit row.
     *
     * @param  list<array{uuid: string, kind: string, pos_x: float, pos_y: float, width: float, height: float, rotation: float, label: string|null}>  $shapes
     * @return array{added: int, changed: int, removed: int}
     */
    private function applyFixtures(RoomLayout $layout, array $shapes): array
    {
        $before = FloorPlanFixture::query()
            ->where('room_layout_id', $layout->getKey())
            ->get()
            ->keyBy('uuid');

        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0];
        $incoming = [];

        foreach ($shapes as $shape) {
            $incoming[$shape['uuid']] = true;
            /** @var FloorPlanFixture|null $old */
            $old = $before->get($shape['uuid']);

            if ($old === null) {
                $counts['added']++;
            } elseif ($this->fixtureDiffers($old, $shape)) {
                $counts['changed']++;
            }
        }

        foreach ($before->keys() as $uuid) {
            if (! isset($incoming[$uuid])) {
                $counts['removed']++;
            }
        }

        if (array_sum($counts) === 0) {
            return $counts;
        }

        FloorPlanFixture::query()->where('room_layout_id', $layout->getKey())->delete();

        $now = now();

        foreach (array_chunk($shapes, 200) as $chunk) {
            FloorPlanFixture::query()->insert(array_map(static fn (array $shape): array => [
                ...$shape,
                'room_layout_id' => $layout->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return $counts;
    }

    /**
     * @param  array{uuid: string, kind: string, pos_x: float, pos_y: float, width: float, height: float, rotation: float, label: string|null}  $shape
     */
    private function fixtureDiffers(FloorPlanFixture $old, array $shape): bool
    {
        return $old->kind->value !== $shape['kind']
            || (float) $old->pos_x !== $shape['pos_x']
            || (float) $old->pos_y !== $shape['pos_y']
            || (float) $old->width !== $shape['width']
            || (float) $old->height !== $shape['height']
            || (float) $old->rotation !== $shape['rotation']
            || $old->label !== $shape['label'];
    }
}
