<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\GuardsAssetImport;
use App\Domains\Assets\Services\Import\AssetImportValidation;
use App\Enums\AssetImportStatus;
use App\Enums\ImportLocationResolution;
use App\Models\AssetImport;
use App\Models\AssetImportLocation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One decision for one location text, applied to every row carrying it
 * (Phase 2.10 R6, SDD DD-97): a room anywhere in the estate, or — always as an
 * explicit choice — no room. A room not in use needs "assign anyway"; an
 * archived room cannot be chosen at all. Choosing "pending" undoes a decision.
 */
final class ResolveAssetImportLocation
{
    use GuardsAssetImport;

    public function __construct(private readonly AssetImportValidation $validation) {}

    public function handle(AssetImport $import, AssetImportLocation $location, ImportLocationResolution $resolution, ?string $roomUuid, bool $assignAnyway, User $actor): AssetImportLocation
    {
        $this->assertStatus($import, [AssetImportStatus::Review]);

        $room = null;
        if ($resolution === ImportLocationResolution::Room) {
            $room = $roomUuid !== null ? Room::query()->with('floor.building')->where('uuid', $roomUuid)->first() : null;
            if ($room === null) {
                throw ValidationException::withMessages(['room' => 'Choose a room from the list.']);
            }
            if (! ($room->is_active && $room->floor?->building?->is_active) && ! $assignAnyway) {
                throw ValidationException::withMessages(['room' => 'That room is not in use. Tick "Assign anyway" to use it.']);
            }
        }

        DB::transaction(function () use ($import, $location, $resolution, $room, $assignAnyway, $actor): void {
            $location->forceFill([
                'resolution' => $resolution,
                'room_id' => $room?->getKey(),
                'assign_anyway' => $room !== null && $assignAnyway,
                'decided_by' => $resolution === ImportLocationResolution::Pending ? null : $actor->getKey(),
                'decided_at' => $resolution === ImportLocationResolution::Pending ? null : now(),
            ])->save();
            $this->nextRevision($import);
            $this->validation->refresh($import);
        });

        return $location->refresh();
    }
}
