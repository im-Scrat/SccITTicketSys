<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\ResolvesAssetReferences;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\PcSpecification;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Register a PC unit (SRS FR-PC-001).
 *
 * The 1:1 specification snapshot (FR-PC-003) is created alongside the unit, even
 * when empty, so the detail page always has a row to edit and no later code has
 * to handle "spec might not exist yet". The `pc_specifications.pc_unit_id`
 * unique constraint makes that 1:1 real at the database level.
 */
class CreatePcUnit
{
    use ResolvesAssetReferences;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, Request $request): PcUnit
    {
        $status = PcStatus::from((string) ($data['status'] ?? PcStatus::Available->value));
        $condition = PcCondition::from((string) ($data['current_condition'] ?? PcCondition::Working->value));
        $room = $this->resolveRoom($data['room'] ?? null);

        $pcUnit = DB::transaction(function () use ($data, $status, $condition, $room, $actor): PcUnit {
            $unit = PcUnit::create([
                'room_id' => $room?->getKey(),
                'unit_code' => $data['unit_code'],
                'asset_tag' => $data['asset_tag'] ?? null,
                'hostname' => $data['hostname'] ?? null,
                'pc_name' => $data['pc_name'],
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'mac_address' => $data['mac_address'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? null,
                'warranty_expiration' => $data['warranty_expiration'] ?? null,
                'status' => $status->value,
                'current_condition' => $condition->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            PcSpecification::query()->create([
                'pc_unit_id' => $unit->getKey(),
                ...UpsertPcSpecification::attributesFrom($data['specification'] ?? []),
            ]);

            return $unit;
        });

        $pcUnit->load(['room.floor.building', 'specification']);

        $this->audit->activity(
            ActivityAction::PcUnitCreated,
            actor: $actor,
            subject: $pcUnit,
            properties: [
                'unit_code' => $pcUnit->unit_code,
                'pc_name' => $pcUnit->pc_name,
                'status' => $status->value,
                'room' => $pcUnit->room?->name,
            ],
            request: $request,
            module: 'assets',
            description: "PC unit {$pcUnit->unit_code} created",
        );

        return $pcUnit;
    }
}
