<?php

declare(strict_types=1);

use App\Enums\ComponentType;
use App\Enums\MaintenanceStatus;
use App\Models\AiPrediction;
use App\Models\HardwareComponent;
use App\Models\HardwareReplacement;
use App\Models\MaintenanceNote;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Database\Seeders\MaintenanceTypeSeeder;

/**
 * WP-M — the repair history shown beside a finding.
 *
 * A finding's `evidence` is frozen at generation; `history` is the machine as it
 * stands *now*, and the two are deliberately different keys. These tests hold
 * both halves of that: the history is live (a repair completed after the finding
 * was filed shows up), the evidence is not (it does not move), and nothing a
 * technician typed crosses onto this surface.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');

    $this->pc = PcUnit::factory()->create(['room_id' => Room::factory()->create()->id]);
    $this->prediction = AiPrediction::factory()->create([
        'pc_unit_id' => $this->pc->id,
        'evidence' => ['observed' => ['completed_repairs' => 3], 'patterns' => [], 'time_window' => ['days' => null, 'basis' => 'none']],
    ]);
});

/**
 * One completed repair on `$pc`, `$daysAgo` days ago, optionally replacing a part.
 *
 * @param  array<string, mixed>  $overrides
 */
function repairOn(PcUnit $pc, int $daysAgo, ?ComponentType $replaced = null, string $type = 'corrective', array $overrides = []): MaintenanceRecord
{
    $record = maintenanceFor(test()->technician, $type, [
        'pc_unit_id' => $pc->id,
        'status' => MaintenanceStatus::Completed->value,
        'completed_at' => now()->subDays($daysAgo),
        ...$overrides,
    ]);

    if ($replaced !== null) {
        $component = HardwareComponent::factory()->create(['component_type' => $replaced->value]);

        HardwareReplacement::factory()->create([
            'maintenance_record_id' => $record->id,
            'pc_unit_id' => $pc->id,
            'old_component_id' => $component->id,
            'new_component_id' => $component->id,
        ]);
    }

    return $record;
}

function historyOf(AiPrediction $prediction): array
{
    return test()->actingAs(test()->admin)
        ->getJson("/api/admin/predictions/{$prediction->uuid}")
        ->assertOk()
        ->json('data.history');
}

it('counts the completed repairs and separates corrective work from care', function (): void {
    repairOn($this->pc, 90, ComponentType::Ram);
    repairOn($this->pc, 60);
    repairOn($this->pc, 30, type: 'preventive');

    $history = historyOf($this->prediction);

    expect($history['completed_repairs'])->toBe(3)
        ->and($history['corrective_repairs'])->toBe(2);
});

it('names the most recent repair, and lists the ones before it newest first', function (): void {
    foreach ([200, 150, 100, 70, 40, 10] as $days) {
        repairOn($this->pc, $days);
    }

    $history = historyOf($this->prediction);

    expect($history['recent_repair']['completed_at'])->toBe(now()->subDays(10)->toIso8601String())
        ->and(collect($history['previous_problems'])->pluck('completed_at')->all())
        ->toBe(collect([40, 70, 100, 150, 200])->map(fn (int $d): string => now()->subDays($d)->toIso8601String())->all());
});

it('lists at most five previous problems, not the recent repair among them', function (): void {
    foreach ([300, 250, 200, 150, 100, 70, 40, 10] as $days) {
        repairOn($this->pc, $days);
    }

    $history = historyOf($this->prediction);

    expect($history['corrective_repairs'])->toBe(8)
        ->and($history['previous_problems'])->toHaveCount(5)
        ->and(collect($history['previous_problems'])->pluck('id')->all())->not->toContain($history['recent_repair']['id']);
});

it('reports the part a repair replaced and the category of the ticket it answered', function (): void {
    $category = TicketCategory::factory()->create(['name' => 'Hardware']);
    $ticket = Ticket::factory()->create(['category_id' => $category->id]);

    repairOn($this->pc, 20, ComponentType::PowerSupply, overrides: ['ticket_id' => $ticket->id]);

    expect(historyOf($this->prediction)['recent_repair'])
        ->toMatchArray(['type' => 'Corrective Repair', 'category' => 'Hardware', 'components' => ['Power Supply']]);
});

it('has no recent repair and no previous problems for a machine with none', function (): void {
    $history = historyOf($this->prediction);

    expect($history)->toBe([
        'completed_repairs' => 0,
        'corrective_repairs' => 0,
        'recent_repair' => null,
        'previous_problems' => [],
    ]);
});

it('counts only completed work on this machine and not a withdrawn record', function (): void {
    repairOn($this->pc, 60);
    repairOn($this->pc, 50, overrides: ['status' => MaintenanceStatus::InProgress->value, 'completed_at' => null]);
    repairOn($this->pc, 40, overrides: ['status' => MaintenanceStatus::Cancelled->value]);
    repairOn(PcUnit::factory()->create(), 30);
    repairOn($this->pc, 20)->delete();

    expect(historyOf($this->prediction)['completed_repairs'])->toBe(1);
});

it('reads the history live while the evidence stays as it was generated', function (): void {
    repairOn($this->pc, 60);

    $before = $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->json('data');

    repairOn($this->pc, 1);

    $after = $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->json('data');

    expect($after['history']['completed_repairs'])->toBe($before['history']['completed_repairs'] + 1)
        ->and($after['history']['recent_repair']['completed_at'])->toBe(now()->subDay()->toIso8601String())
        // The finding's own reasoning did not move.
        ->and($after['evidence'])->toBe($before['evidence']);
});

it('never lets a technician\'s free text cross onto this surface', function (): void {
    $ticket = Ticket::factory()->create(['title' => 'PRIVATE-TICKET-TITLE']);

    $record = repairOn($this->pc, 20, ComponentType::Ram, overrides: [
        'ticket_id' => $ticket->id,
        'title' => 'PRIVATE-RECORD-TITLE',
        'diagnosis' => 'PRIVATE-DIAGNOSIS-TEXT',
        'root_cause' => 'PRIVATE-ROOT-CAUSE-TEXT',
        'resolution' => 'PRIVATE-RESOLUTION-TEXT',
        'preventive_recommendation' => 'PRIVATE-RECOMMENDATION-TEXT',
    ]);

    MaintenanceNote::factory()->create(['maintenance_record_id' => $record->id, 'body' => 'PRIVATE-NOTE-TEXT']);

    // Asserted against the *encoded* payload, so a field cannot leak back in
    // through a later change to the resource.
    $body = $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertOk()->getContent();

    expect($body)->not->toContain('PRIVATE-');
});

it('does not put the history in the list, where it would cost a query per row', function (): void {
    repairOn($this->pc, 20);

    $row = $this->actingAs($this->admin)->getJson('/api/admin/predictions')->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('history')->and($row)->not->toHaveKey('evidence');
});

it('is closed to everyone but an administrator, like the rest of the finding', function (): void {
    repairOn($this->pc, 20);

    $teacher = userWithRole('teacher');

    foreach ([$this->technician, $teacher] as $actor) {
        $body = $this->actingAs($actor)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertForbidden()->getContent();

        expect($body)->not->toContain('completed_repairs')->not->toContain('recent_repair');
    }
});
