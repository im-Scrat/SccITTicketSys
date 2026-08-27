<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Building;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create a building (SRS FR-LOC-001). Transactional, stamps the blame columns,
 * and records the event on the location audit trail (FR-AUD-003).
 */
class CreateBuilding
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, Request $request): Building
    {
        $building = DB::transaction(fn (): Building => Building::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]));

        $this->audit->activity(
            ActivityAction::LocationCreated,
            actor: $actor,
            subject: $building,
            properties: ['name' => $building->name, 'code' => $building->code],
            request: $request,
            module: 'locations',
            description: "Building {$building->name} created",
        );

        return $building;
    }
}
