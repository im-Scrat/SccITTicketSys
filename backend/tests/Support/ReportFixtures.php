<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Building;
use App\Models\Floor;
use App\Models\HardwareComponent;
use App\Models\HardwareReplacement;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
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

    /**
     * A PC unit in a room (Overall Reports fixtures).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function pc(?Room $room, array $attributes = []): PcUnit
    {
        return PcUnit::factory()->create(['room_id' => $room?->id, ...$attributes]);
    }

    /**
     * A maintenance record completed at a school-local time, of a seeded type
     * (requires MaintenanceTypeSeeder): preventive, corrective, hardware-upgrade…
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function repair(PcUnit $pc, string $typeSlug, string $completedLocal, array $attributes = []): MaintenanceRecord
    {
        return MaintenanceRecord::factory()->create([
            'pc_unit_id' => $pc->id,
            'maintenance_type_id' => MaintenanceType::query()->where('slug', $typeSlug)->value('id'),
            'status' => 'completed',
            'completed_at' => self::local($completedLocal)->utc(),
            'maintenance_date' => self::local($completedLocal)->utc(),
            ...$attributes,
        ]);
    }

    /** A replacement on a repair; null component types leave that side unrecorded. */
    public static function replacement(MaintenanceRecord $record, ?string $oldType, ?string $newType, string $replacedLocal): HardwareReplacement
    {
        return HardwareReplacement::factory()->create([
            'maintenance_record_id' => $record->id,
            'pc_unit_id' => $record->pc_unit_id,
            'old_component_id' => $oldType !== null ? HardwareComponent::factory()->create(['component_type' => $oldType])->id : null,
            'new_component_id' => $newType !== null ? HardwareComponent::factory()->create(['component_type' => $newType])->id : null,
            'replaced_at' => self::local($replacedLocal)->utc(),
        ]);
    }
}
