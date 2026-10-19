<?php

declare(strict_types=1);

namespace App\Domains\Locations\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Building;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a building (SRS FR-LOC-001). Transactional, stamps the blame columns,
 * and records the event on the location audit trail (FR-AUD-003).
 *
 * A building given no code gets one derived from its name ("Science Hall" ->
 * SCIENCE-HALL, then SCIENCE-HALL-2 ...), unique across archived buildings too.
 * The check-then-insert can still lose a race to a concurrent create of the
 * same name; the unique index is the real guarantee, so a collision on a
 * *derived* code is retried with the next suffix. Each attempt is its own
 * transaction, because a failed insert aborts the PostgreSQL transaction it
 * ran in. A code the administrator typed is never altered: its collision is a
 * validation error, reported before this runs.
 */
class CreateBuilding
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, Request $request): Building
    {
        $given = isset($data['code']) && is_string($data['code']) && $data['code'] !== '' ? $data['code'] : null;

        for ($attempt = 1; ; $attempt++) {
            $code = $given ?? $this->derivedCode((string) $data['name']);

            try {
                $building = DB::transaction(fn (): Building => Building::create([
                    'name' => $data['name'],
                    'code' => $code,
                    'description' => $data['description'] ?? null,
                    'address' => $data['address'] ?? null,
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]));
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($given !== null || $attempt >= 5) {
                    throw $e;
                }
            }
        }

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

    /**
     * The first free code for this name: its slug, upper-cased and kept inside
     * the 50-character limit with room for a suffix. A name with no letters or
     * digits a slug can keep (e.g. one written wholly in another script) falls
     * back to BLDG.
     */
    private function derivedCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name), 40, ''));
        $base = trim($base, '-') !== '' ? trim($base, '-') : 'BLDG';

        $taken = Building::withTrashed()
            ->where('code', $base)
            ->orWhere('code', 'like', $base.'-%')
            ->pluck('code')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($n = 2; ; $n++) {
            if (! in_array("{$base}-{$n}", $taken, true)) {
                return "{$base}-{$n}";
            }
        }
    }
}
