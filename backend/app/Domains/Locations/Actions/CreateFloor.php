<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Building;
use App\Models\Floor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create a floor within a building (SRS FR-LOC-002). `(building_id,
 * floor_number)` uniqueness is validated in the FormRequest for a friendly 422
 * and enforced by the database unique constraint as the backstop.
 */
class CreateFloor
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Building $building, array $data, User $actor, Request $request): Floor
    {
        $floor = DB::transaction(fn (): Floor => Floor::create([
            'building_id' => $building->getKey(),
            'floor_number' => (int) $data['floor_number'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]));

        $this->audit->activity(
            ActivityAction::LocationCreated,
            actor: $actor,
            subject: $floor,
            properties: [
                'building' => $building->name,
                'floor_number' => $floor->floor_number,
                'name' => $floor->name,
            ],
            request: $request,
            module: 'locations',
            description: "Floor {$floor->name} created in {$building->name}",
        );

        return $floor->load('building');
    }
}
