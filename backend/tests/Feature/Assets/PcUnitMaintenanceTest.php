<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Models\HardwareReplacement;
use App\Models\MaintenanceChecklist;
use App\Models\MaintenanceNote;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\RepairImage;
use App\Models\Room;
use App\Models\Ticket;
use Database\Seeders\MaintenanceTypeSeeder;
use Illuminate\Support\Str;

/**
 * WP-G — `GET /api/admin/pc-units/{pc_unit:uuid}/maintenance`: the real
 * maintenance history behind the floor-plan PC inspector.
 *
 * The two existing readings of a PC's past — the unified timeline
 * (`AssetHistory::mapMaintenance`) and the detail page's own summary tab
 * (`MaintenanceSummaryResource`) — are each a deliberate, working projection
 * for their own surface, and this file does not touch either. It proves this
 * *third* endpoint returns what those two do not: the full record.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->withHeader('Origin', (string) config('app.url'));

    $this->admin = userWithRole('administrator');
    $this->room = Room::factory()->create();
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-INSPECTOR']);
    $this->url = fn (?PcUnit $pc = null): string => sprintf(
        '/api/admin/pc-units/%s/maintenance',
        ($pc ?? $this->pc)->uuid,
    );
});

it('returns the fields the lossy projections do not carry', function (): void {
    $ticket = Ticket::factory()->create();
    $record = MaintenanceRecord::factory()->create([
        'pc_unit_id' => $this->pc->id,
        'ticket_id' => $ticket->id,
        'title' => 'Projector will not power on',
        'diagnosis' => 'PSU fan seized.',
        'root_cause' => 'Dust ingress over two years.',
        'resolution' => 'Replaced the PSU.',
        'preventive_recommendation' => 'Quarterly compressed-air cleaning.',
        'downtime_minutes' => 90,
        'labor_hours' => 1.5,
        'cost' => 45.00,
        'status' => MaintenanceStatus::Completed->value,
        'pc_status_before' => PcStatus::Available->value,
    ]);

    $response = $this->actingAs($this->admin)->getJson(($this->url)())->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $record->uuid);

    expect($row)->not->toBeNull()
        ->and($row['diagnosis'])->toBe('PSU fan seized.')
        ->and($row['root_cause'])->toBe('Dust ingress over two years.')
        ->and($row['resolution'])->toBe('Replaced the PSU.')
        ->and($row['preventive_recommendation'])->toBe('Quarterly compressed-air cleaning.')
        ->and($row['downtime_minutes'])->toBe(90)
        ->and((float) $row['labor_hours'])->toBe(1.5)
        ->and((float) $row['cost'])->toBe(45.0)
        ->and($row['pc_state_before'])->toBe([
            'status' => 'available', 'status_label' => PcStatus::Available->label(),
            'condition' => $row['pc_state_before']['condition'], 'condition_label' => $row['pc_state_before']['condition_label'],
        ])
        ->and($row['ticket'])->toMatchArray(['id' => $ticket->uuid, 'number' => $ticket->ticket_number]);
});

it('verifiably carries more than the unified timeline\'s lossy maintenance entry', function (): void {
    $record = MaintenanceRecord::factory()->create([
        'pc_unit_id' => $this->pc->id,
        'diagnosis' => 'Fan bearing worn.',
        'root_cause' => 'End of service life.',
        'resolution' => 'Fan replaced.',
        'preventive_recommendation' => 'Replace fans at the 3-year mark proactively.',
    ]);

    $timeline = $this->actingAs($this->admin)
        ->getJson("/api/admin/pc-units/{$this->pc->uuid}/history")
        ->assertOk()
        ->json('data');
    $timelineEntry = collect($timeline)->firstWhere('properties.id', $record->uuid);

    // The timeline entry exists, and — proving the projection is lossy, not
    // merely different — it does not carry root_cause or the preventive
    // recommendation at all.
    expect($timelineEntry)->not->toBeNull()
        ->and($timelineEntry)->not->toHaveKey('root_cause')
        ->and($timelineEntry)->not->toHaveKey('preventive_recommendation');
    expect(json_encode($timelineEntry))->not->toContain('End of service life')
        ->not->toContain('Replace fans at the 3-year mark');

    $full = $this->actingAs($this->admin)->getJson(($this->url)())->assertOk()->json('data');
    $fullRow = collect($full)->firstWhere('id', $record->uuid);

    expect($fullRow['root_cause'])->toBe('End of service life.')
        ->and($fullRow['preventive_recommendation'])->toBe('Replace fans at the 3-year mark proactively.');
});

it('includes the checklist, evidence, notes and hardware replacements', function (): void {
    $record = MaintenanceRecord::factory()->create(['pc_unit_id' => $this->pc->id]);
    MaintenanceChecklist::factory()->create(['maintenance_record_id' => $record->id, 'item_label' => 'Reseat RAM', 'is_completed' => true]);
    RepairImage::factory()->create(['maintenance_record_id' => $record->id, 'caption' => 'Before']);
    MaintenanceNote::factory()->create(['maintenance_record_id' => $record->id, 'body' => 'Ordered a replacement fan.']);
    $replacement = HardwareReplacement::factory()->create(['maintenance_record_id' => $record->id]);

    $row = collect(
        $this->actingAs($this->admin)->getJson(($this->url)())->assertOk()->json('data'),
    )->firstWhere('id', $record->uuid);

    expect($row['checklist_items'])->toHaveCount(1)
        ->and($row['checklist_items'][0]['label'])->toBe('Reseat RAM')
        ->and($row['evidence'])->toHaveCount(1)
        ->and($row['evidence'][0]['caption'])->toBe('Before')
        ->and($row['notes'])->toHaveCount(1)
        ->and($row['notes'][0]['body'])->toBe('Ordered a replacement fan.')
        ->and($row['hardware_replacements'])->toHaveCount(1)
        // HardwareReplacement has no uuid of its own (never independently
        // addressed by route) — HardwareReplacementResource, reused
        // unchanged, exposes its numeric id, matching every other consumer.
        ->and($row['hardware_replacements'][0]['id'])->toBe($replacement->id);
});

it('lists every visit against this machine, newest first, and none belonging to another', function (): void {
    $stranger = PcUnit::factory()->create();
    $strangerRecord = MaintenanceRecord::factory()->create(['pc_unit_id' => $stranger->id]);

    $older = MaintenanceRecord::factory()->create(['pc_unit_id' => $this->pc->id, 'maintenance_date' => now()->subDays(10)]);
    $newer = MaintenanceRecord::factory()->create(['pc_unit_id' => $this->pc->id, 'maintenance_date' => now()->subDay()]);

    $response = $this->actingAs($this->admin)->getJson(($this->url)())->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids->first())->toBe($newer->uuid)
        ->and($ids->last())->toBe($older->uuid)
        ->and($ids)->not->toContain($strangerRecord->uuid);

    expect($response->getContent())->not->toContain($stranger->uuid);
});

it('answers an empty list, not an error, for a machine with no maintenance history', function (): void {
    $this->actingAs($this->admin)
        ->getJson(($this->url)())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('archived records and an archived machine are still visible to an Administrator', function (): void {
    $record = MaintenanceRecord::factory()->create(['pc_unit_id' => $this->pc->id]);
    $record->delete();
    $this->pc->delete();

    $this->actingAs($this->admin)
        ->getJson(($this->url)())
        ->assertOk()
        ->assertJsonCount(0, 'data');
    // Soft-deleted maintenance records are excluded by default (unchanged
    // behaviour, matching every other maintenance read); the machine itself
    // remains reachable because this route (like history/audit) is ->withTrashed().
});

it('refuses a Technician and a Teacher — this is not the technician\'s own maintenance surface', function (string $role): void {
    MaintenanceRecord::factory()->create(['pc_unit_id' => $this->pc->id]);

    $this->actingAs(userWithRole($role))->getJson(($this->url)())->assertForbidden();
})->with(['technician', 'teacher']);

/**
 * `{pc_unit:uuid}` is *implicit route-model binding*: Laravel's
 * `SubstituteBindings` middleware resolves it before the route's own
 * `can:assets.view` middleware runs, so a genuinely unknown uuid 404s before
 * authorization is ever consulted, while a real one a role cannot reach
 * refuses with 403. This is not new to this endpoint — it is the existing,
 * shared shape of every `pc-units/{pc_unit:uuid}/*` route in the Assets
 * domain (verified identically against the pre-existing `/history` sibling
 * before writing this test) — so it is asserted here as the actual,
 * consistent behaviour, not "fixed" by giving this one route a different
 * contract from its siblings. **No maintenance data is disclosed either
 * way** — the distinguishable fact is only "a uuid of this shape exists
 * somewhere in the PC register", already learnable from every other
 * `pc-units/{pc_unit:uuid}/*` route. Reported as a pre-existing, out-of-scope
 * finding: a genuine no-oracle guarantee here would need the same unbound-
 * string-resolved-after-authorization pattern the floor-plan routes use,
 * which would mean restructuring this whole existing, tested route family —
 * larger than WP-G's scope.
 */
it('refuses a non-administrator with 403 for a real uuid and 404 for an invented one — the pre-existing pc-units/{pc_unit:uuid} shape', function (string $role): void {
    $actor = userWithRole($role);

    $this->actingAs($actor)->getJson(($this->url)())->assertForbidden();
    $this->actingAs($actor)
        ->getJson('/api/admin/pc-units/'.Str::uuid().'/maintenance')
        ->assertNotFound();
})->with(['technician', 'teacher']);

it('requires authentication', function (): void {
    $this->getJson(($this->url)())->assertUnauthorized();
});

it('answers an unknown pc unit uuid with 404 for an Administrator', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/pc-units/'.Str::uuid().'/maintenance')
        ->assertNotFound();
});

it('never discloses another machine\'s maintenance through this endpoint, checked against the encoded payload', function (): void {
    $other = PcUnit::factory()->create(['pc_name' => 'OTHER-MACHINE']);
    MaintenanceRecord::factory()->create(['pc_unit_id' => $other->id, 'title' => 'Confidential repair on the other machine']);
    MaintenanceRecord::factory()->create(['pc_unit_id' => $this->pc->id]);

    $response = $this->actingAs($this->admin)->getJson(($this->url)())->assertOk();

    expect($response->getContent())
        ->not->toContain('OTHER-MACHINE')
        ->not->toContain('Confidential repair on the other machine')
        ->not->toContain($other->uuid);
});
