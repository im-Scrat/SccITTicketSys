<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\ImportLocationMatch;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Matches a row's location text to a room that already exists (Phase 2.10 R6,
 * SDD DD-97). An import never creates a building, floor or room.
 *
 * Only two things are applied without a decision:
 *
 *  - an **exact room code** (case-insensitive) with nothing in the row
 *    contradicting it, or
 *  - an exact **building + floor + room**.
 *
 * Everything else — a room *name* alone (even a unique one: two schools'
 * "Computer Lab" are not the same room, and next term this one may not be
 * either), a combined "Building A, Lab 2" text, a contradiction — is
 * `ambiguous`: the candidates are offered with their reasons and the
 * administrator chooses, one decision per distinct text. Suggestions by
 * similarity are offered too, and never applied.
 *
 * A room that is not in use (inactive, or in an inactive building) is
 * `not_selectable`: it can be chosen only with "assign anyway". Archived rooms
 * are never candidates.
 */
final class ImportLocationMatcher
{
    /** @var Collection<int, Room>|null */
    private ?Collection $rooms = null;

    /**
     * The key one location decision is stored under: the lower-cased parts.
     *
     * @param  array<string, string|null>  $parts
     */
    public static function normalizedKey(array $parts): string
    {
        $clean = [];
        foreach (['building', 'floor', 'room', 'room_code', 'location'] as $part) {
            $value = trim((string) ($parts[$part] ?? ''));
            if ($value !== '') {
                $clean[$part] = mb_strtolower((string) preg_replace('/\s+/', ' ', $value));
            }
        }

        return $clean === [] ? '' : (string) json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The location text as the administrator wrote it, for display.
     *
     * @param  array<string, string|null>  $parts
     */
    public static function sourceText(array $parts): string
    {
        $labels = ['building' => 'Building', 'floor' => 'Floor', 'room' => 'Room', 'room_code' => 'Room code', 'location' => 'Location'];
        $out = [];
        foreach ($labels as $part => $label) {
            $value = trim((string) ($parts[$part] ?? ''));
            if ($value !== '') {
                $out[] = "{$label}: {$value}";
            }
        }

        return $out === [] ? 'No location given' : mb_substr(implode(' · ', $out), 0, 512);
    }

    /**
     * @param  array<string, string|null>  $parts
     * @return array{match: ImportLocationMatch, room_id: int|null, candidates: list<array<string, mixed>>}
     */
    public function match(array $parts): array
    {
        $rooms = $this->rooms();
        $get = fn (string $k): ?string => ($v = trim((string) ($parts[$k] ?? ''))) === '' ? null : $v;
        [$building, $floor, $roomText, $code, $combined] = [$get('building'), $get('floor'), $get('room'), $get('room_code'), $get('location')];

        if ($building === null && $floor === null && $roomText === null && $code === null && $combined === null) {
            return ['match' => ImportLocationMatch::Unmatched, 'room_id' => null, 'candidates' => []];
        }

        // 1. A room code: given as one, or a room / location value that *is* one.
        $codeText = $code ?? ($roomText !== null && $this->byCode($roomText) !== null ? $roomText : null)
            ?? ($combined !== null && $this->byCode($combined) !== null ? $combined : null);
        if ($codeText !== null) {
            $room = $this->byCode($codeText);
            if ($room === null) {
                return $this->fallback($parts, [], 'No room has code '.$codeText.'.');
            }
            $contradiction = $this->contradiction($room, $building, $floor, $code !== null ? $roomText : null);
            if ($contradiction !== null) {
                return ['match' => ImportLocationMatch::Ambiguous, 'room_id' => null, 'candidates' => [$this->candidate($room, $contradiction)]];
            }

            return $this->selectable($room)
                ? ['match' => ImportLocationMatch::Matched, 'room_id' => $room->id, 'candidates' => [$this->candidate($room, 'Room code matches')]]
                : ['match' => ImportLocationMatch::NotSelectable, 'room_id' => null, 'candidates' => [$this->candidate($room, 'Room code matches, but the room is not in use')]];
        }

        // 2. Building + floor + room, each exactly.
        if ($building !== null && $floor !== null && $roomText !== null) {
            $found = $rooms->filter(fn (Room $r): bool => $this->buildingIs($r, $building) && $this->floorIs($r, $floor) && $this->roomIs($r, $roomText))->values();
            if ($found->count() === 1) {
                $room = $found->first();

                return $this->selectable($room)
                    ? ['match' => ImportLocationMatch::Matched, 'room_id' => $room->id, 'candidates' => [$this->candidate($room, 'Building, floor and room match')]]
                    : ['match' => ImportLocationMatch::NotSelectable, 'room_id' => null, 'candidates' => [$this->candidate($room, 'Building, floor and room match, but the room is not in use')]];
            }
        }

        // 3. Names and partial matches: offered, never applied.
        $candidates = [];
        foreach (array_filter([$roomText, $combined]) as $text) {
            foreach ($rooms as $room) {
                if ($this->roomIs($room, $text)) {
                    $candidates[$room->id] ??= $this->candidate($room, 'Room name matches');
                } elseif ($this->mentionsCode($text, $room)) {
                    $candidates[$room->id] ??= $this->candidate($room, 'Mentions the room code');
                }
            }
        }
        if ($building !== null || $floor !== null) {
            foreach ($candidates as $id => $candidate) {
                $room = $rooms->firstWhere('id', $id);
                if ($room !== null && $this->contradiction($room, $building, $floor, null) !== null) {
                    $candidates[$id]['reason'] .= ', but the building or floor differs';
                }
            }
        }

        return $this->fallback($parts, array_values($candidates));
    }

    /** Ids of rooms an administrator may assign without "assign anyway". */
    public function selectable(Room $room): bool
    {
        return $room->is_active && (bool) $room->floor?->building?->is_active;
    }

    /**
     * Similarity suggestions when nothing matched exactly.
     *
     * @param  array<string, string|null>  $parts
     * @param  list<array<string, mixed>>  $candidates
     * @return array{match: ImportLocationMatch, room_id: null, candidates: list<array<string, mixed>>}
     */
    private function fallback(array $parts, array $candidates, ?string $note = null): array
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([$parts['room'] ?? null, $parts['location'] ?? null, $parts['room_code'] ?? null]))));
        if ($candidates === [] && $text !== '') {
            $scored = [];
            foreach ($this->rooms() as $room) {
                similar_text($text, mb_strtolower($room->name), $byName);
                similar_text($text, mb_strtolower($room->code), $byCode);
                $score = max($byName, $byCode);
                if ($score >= 60) {
                    $scored[] = [$score, $room];
                }
            }
            usort($scored, fn (array $a, array $b): int => $b[0] <=> $a[0]);
            foreach (array_slice($scored, 0, 5) as [, $room]) {
                $candidates[] = $this->candidate($room, 'Similar name');
            }
        }

        if ($note !== null) {
            foreach ($candidates as &$candidate) {
                $candidate['note'] = $note;
            }
            unset($candidate);
        }

        return [
            'match' => $candidates === [] ? ImportLocationMatch::Unmatched : ImportLocationMatch::Ambiguous,
            'room_id' => null,
            'candidates' => $candidates,
        ];
    }

    private function contradiction(Room $room, ?string $building, ?string $floor, ?string $roomText): ?string
    {
        if ($building !== null && ! $this->buildingIs($room, $building)) {
            return 'Room code matches, but the building is '.($room->floor->building->name ?? 'different');
        }
        if ($floor !== null && ! $this->floorIs($room, $floor)) {
            return 'Room code matches, but the floor is '.($room->floor->name ?? 'different');
        }
        if ($roomText !== null && ! $this->roomIs($room, $roomText)) {
            return 'Room code matches, but the room is called '.$room->name;
        }

        return null;
    }

    private function byCode(string $code): ?Room
    {
        $lower = mb_strtolower(trim($code));

        return $this->rooms()->first(fn (Room $r): bool => mb_strtolower($r->code) === $lower);
    }

    private function buildingIs(Room $room, string $text): bool
    {
        $text = mb_strtolower(trim($text));
        $building = $room->floor?->building;

        return $building !== null && (mb_strtolower($building->code) === $text || mb_strtolower($building->name) === $text);
    }

    private function floorIs(Room $room, string $text): bool
    {
        $floor = $room->floor;
        if ($floor === null) {
            return false;
        }
        if (mb_strtolower(trim($floor->name)) === mb_strtolower(trim($text))) {
            return true;
        }

        return preg_match('/(-?\d+)/', $text, $m) === 1 && (int) $m[1] === $floor->floor_number;
    }

    private function roomIs(Room $room, string $text): bool
    {
        $text = mb_strtolower(trim($text));

        return mb_strtolower($room->name) === $text || mb_strtolower($room->code) === $text;
    }

    private function mentionsCode(string $text, Room $room): bool
    {
        return mb_strlen($room->code) >= 2
            && preg_match('/(^|[^\p{L}\p{N}])'.preg_quote(mb_strtolower($room->code), '/').'($|[^\p{L}\p{N}])/u', mb_strtolower($text)) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(Room $room, string $reason): array
    {
        return [
            'uuid' => $room->uuid,
            'label' => self::roomLabel($room),
            'code' => $room->code,
            'reason' => $reason,
            'selectable' => $this->selectable($room),
        ];
    }

    public static function roomLabel(Room $room): string
    {
        return implode(' › ', array_filter([$room->floor?->building?->name, $room->floor?->name, $room->name])).' ('.$room->code.')';
    }

    /**
     * Every room that is not archived, with its floor and building.
     *
     * @return Collection<int, Room>
     */
    private function rooms(): Collection
    {
        return $this->rooms ??= Room::query()
            ->whereHas('floor', fn ($f) => $f->whereHas('building'))
            ->with(['floor.building'])
            ->get();
    }
}
