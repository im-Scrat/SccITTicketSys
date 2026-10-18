<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for report tests (Phase 2.10): rooms on explicit floors and tickets
 * filed at exact school-local times.
 *
 * A class rather than global helpers, so nothing collides with the suite's
 * other test-file functions. Floors take an explicit number: `FloorFactory`
 * picks one at random and collides on (building_id, floor_number).
 */
final class ReportFixtures
{
    /** A migration file's instance, to re-run or inspect it ("2026_10_18_100000_add_…"). */
    public static function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    /** A school-local instant: "2026-10-18 09:30" in Asia/Manila. */
    public static function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, SchoolClock::TIMEZONE);
    }

    public static function floor(?Building $building = null, int $number = 1): Floor
    {
        return Floor::factory()->create([
            'building_id' => ($building ?? Building::factory()->create())->id,
            'floor_number' => $number,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function room(Floor $floor, string $type = 'laboratory', array $attributes = []): Room
    {
        return Room::factory()->create(['floor_id' => $floor->id, 'room_type' => $type, ...$attributes]);
    }

    /**
     * A ticket filed at a school-local time ("2026-10-18 09:30"), on the seeded
     * lookups (requires TicketLookupSeeder).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function ticketAt(User $reporter, string $localTime, string $status = 'open', array $overrides = []): Ticket
    {
        return ticketFor($reporter, $status, [
            // In UTC: Eloquent formats a date attribute without converting its
            // zone, so a Manila instant would be stored eight hours late.
            'created_at' => self::local($localTime)->utc(),
            ...$overrides,
        ]);
    }

    /** A status-history row at a school-local time, as TicketLifecycle writes it. */
    public static function history(Ticket $ticket, ?string $from, string $to, string $localTime, ?User $by = null): void
    {
        DB::table('ticket_status_history')->insert([
            'ticket_id' => $ticket->id,
            'from_status_id' => $from !== null ? TicketStatus::query()->where('slug', $from)->value('id') : null,
            'to_status_id' => TicketStatus::query()->where('slug', $to)->value('id'),
            'changed_by' => $by?->id,
            'created_at' => CarbonImmutable::parse($localTime, SchoolClock::TIMEZONE)->utc(),
        ]);
    }
}
