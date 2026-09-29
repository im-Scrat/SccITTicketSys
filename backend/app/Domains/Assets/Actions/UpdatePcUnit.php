<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\ResolvesAssetReferences;
use App\Domains\FloorPlan\Events\PcStatusChanged;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Update a PC unit (SRS FR-PC-001/002), including moving it to another room.
 *
 * Unlike a serialized asset, a PC has no `pc_transfers` ledger in the baselined
 * schema, so a room move is recorded in the audit properties rather than a
 * separate table — the timeline still explains where the machine used to be.
 *
 * `status` and `current_condition` are ordinary fields here (FR-PC-002): the
 * lifecycle machinery with its own history table belongs to serialized assets,
 * and inventing a parallel one for PCs would duplicate `PcStatus` without a
 * requirement asking for it.
 */
class UpdatePcUnit
{
    use ResolvesAssetReferences;

    /** @var list<string> */
    private const SCALAR_FIELDS = [
        'unit_code',
        'pc_name',
        'asset_tag',
        'hostname',
        'brand',
        'model',
        'serial_number',
        'ip_address',
        'mac_address',
        'purchase_date',
        'warranty_expiration',
        'notes',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PcUnit $pcUnit, array $data, User $actor, Request $request): PcUnit
    {
        $pcUnit->loadMissing('room.floor.building');

        $changes = [];
        $attributes = [];

        foreach (self::SCALAR_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $new = $this->stringify($data[$field]);
            $old = $this->stringify($pcUnit->{$field});

            if ($old !== $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }

            $attributes[$field] = $data[$field];
        }

        if (array_key_exists('status', $data)) {
            $status = PcStatus::from((string) $data['status']);

            if ($status !== $pcUnit->status) {
                $changes['status'] = ['from' => $pcUnit->status->value, 'to' => $status->value];
            }

            $attributes['status'] = $status->value;
        }

        if (array_key_exists('current_condition', $data)) {
            $condition = PcCondition::from((string) $data['current_condition']);

            if ($condition !== $pcUnit->current_condition) {
                $changes['current_condition'] = ['from' => $pcUnit->current_condition->value, 'to' => $condition->value];
            }

            $attributes['current_condition'] = $condition->value;
        }

        if (array_key_exists('room', $data)) {
            $room = $this->resolveRoom($data['room']);

            if ($room?->getKey() !== $pcUnit->room_id) {
                $room?->loadMissing('floor.building');
                $changes['room'] = ['from' => $pcUnit->room?->name, 'to' => $room?->name];
                $attributes['room_id'] = $room?->getKey();
            }
        }

        if ($attributes === []) {
            return $pcUnit;
        }

        DB::transaction(function () use ($pcUnit, $attributes, $actor): void {
            $pcUnit->fill([...$attributes, 'updated_by' => $actor->getKey()])->save();
        });

        $this->audit->activity(
            ActivityAction::PcUnitUpdated,
            actor: $actor,
            subject: $pcUnit,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'assets',
            description: "PC unit {$pcUnit->unit_code} updated",
        );

        $updated = $pcUnit->refresh()->load(['room.floor.building', 'specification']);

        /*
         * WP-E — the floor-plan notification seam (FR-FP-007). Outside the
         * transaction and after it has committed, same convention as every
         * other domain event here; only when `status` actually moved, and only
         * to the unit's *current* room's channel — a machine with no room has
         * no floor-plan viewer to tell.
         */
        if (array_key_exists('status', $changes) && $updated->room !== null) {
            PcStatusChanged::dispatch(
                $updated->room->uuid,
                $updated->uuid,
                $updated->pc_name,
                $updated->unit_code,
                ['value' => $updated->status->value, 'label' => $updated->status->label(), 'tone' => $updated->status->tone()],
            );
        }

        return $updated;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
