<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Floor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Update a floor (SRS FR-LOC-002). Renumbering is permitted — the public
 * identifier is the `uuid`, not the floor number — and stays subject to the
 * `(building_id, floor_number)` unique constraint.
 */
class UpdateFloor
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Floor $floor, array $data, User $actor, Request $request): Floor
    {
        $changes = [];

        if (array_key_exists('floor_number', $data) && (int) $data['floor_number'] !== (int) $floor->floor_number) {
            $changes['floor_number'] = ['from' => (int) $floor->floor_number, 'to' => (int) $data['floor_number']];
        }

        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $floor->{$field}) {
                $changes[$field] = ['from' => $floor->{$field}, 'to' => $data[$field]];
            }
        }

        DB::transaction(function () use ($floor, $data, $actor): void {
            $floor->fill([
                'floor_number' => isset($data['floor_number']) ? (int) $data['floor_number'] : $floor->floor_number,
                'name' => $data['name'] ?? $floor->name,
                'description' => $data['description'] ?? null,
                'updated_by' => $actor->getKey(),
            ]);

            $floor->save();
        });

        $this->audit->activity(
            ActivityAction::LocationUpdated,
            actor: $actor,
            subject: $floor,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'locations',
            description: "Floor {$floor->name} updated",
        );

        return $floor->refresh()->load('building');
    }
}
