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
 * Update a building's attributes (SRS FR-LOC-001). Records the changed fields
 * (old → new) on the audit trail so the timeline explains what moved.
 */
class UpdateBuilding
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Building $building, array $data, User $actor, Request $request): Building
    {
        $changes = [];

        foreach (['name', 'code', 'description', 'address'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $building->{$field}) {
                $changes[$field] = ['from' => $building->{$field}, 'to' => $data[$field]];
            }
        }

        DB::transaction(function () use ($building, $data, $actor): void {
            $building->fill([
                'name' => $data['name'] ?? $building->name,
                'code' => $data['code'] ?? $building->code,
                'description' => $data['description'] ?? null,
                'address' => $data['address'] ?? null,
                'updated_by' => $actor->getKey(),
            ]);

            $building->save();
        });

        $this->audit->activity(
            ActivityAction::LocationUpdated,
            actor: $actor,
            subject: $building,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'locations',
            description: "Building {$building->name} updated",
        );

        return $building->refresh();
    }
}
