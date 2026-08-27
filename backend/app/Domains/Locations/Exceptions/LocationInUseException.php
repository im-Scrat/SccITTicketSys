<?php

declare(strict_types=1);

namespace App\Domains\Locations\Exceptions;

use App\Models\Room;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when archiving a building, floor or room would strand live occupants
 * (SRS FR-LOC-004). Renders its own 422 — the same self-rendering pattern as
 * AccountNotActiveException — carrying a machine-readable blocker report so the
 * UI can name what is in the way and offer to reassign it.
 *
 * Response shape:
 *   {
 *     "message": "...",
 *     "code": "location_in_use",
 *     "blockers": { "pc_units": 3, "assets": 0, "consumables": 1, "open_tickets": 2 },
 *     "rooms": [ { "id": "<uuid>", "name": "...", "label": "...", "blockers": {...} } ]
 *   }
 */
class LocationInUseException extends RuntimeException
{
    /**
     * @param  array{pc_units: int, assets: int, consumables: int, open_tickets: int}  $blockers
     * @param  list<array{room: Room, blockers: array{pc_units: int, assets: int, consumables: int, open_tickets: int}}>  $breakdown
     */
    public function __construct(
        private readonly string $level,
        private readonly array $blockers,
        private readonly array $breakdown = [],
    ) {
        parent::__construct(self::summarize($level, $blockers));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'location_in_use',
            'level' => $this->level,
            'blockers' => $this->blockers,
            'rooms' => array_map(static fn (array $entry): array => [
                'id' => $entry['room']->uuid,
                'name' => $entry['room']->name,
                'label' => self::roomLabel($entry['room']),
                'blockers' => $entry['blockers'],
            ], $this->breakdown),
        ], 422);
    }

    /**
     * @param  array{pc_units: int, assets: int, consumables: int, open_tickets: int}  $blockers
     */
    private static function summarize(string $level, array $blockers): string
    {
        $parts = [];

        foreach ([
            'pc_units' => 'PC unit',
            'assets' => 'asset',
            'consumables' => 'consumable line',
            'open_tickets' => 'open ticket',
        ] as $key => $noun) {
            $count = $blockers[$key];

            if ($count > 0) {
                $parts[] = $count.' '.$noun.($count === 1 ? '' : 's');
            }
        }

        $held = $parts === [] ? 'live records' : implode(', ', $parts);

        return "This {$level} still holds {$held}. Reassign the equipment and stock to another "
            .'room — and resolve or close the open tickets — before archiving it.';
    }

    private static function roomLabel(Room $room): string
    {
        $floor = $room->floor;
        $building = $floor?->building;

        $parts = array_values(array_filter([
            $building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $room->name,
        ]));

        return implode(' · ', $parts);
    }
}
