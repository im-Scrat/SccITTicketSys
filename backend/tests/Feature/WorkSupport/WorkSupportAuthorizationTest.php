<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\QrCode;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;

/**
 * WP-2.6b Stage E — who may raise, read and decide a work support request
 * (SRS FR-WSR-009/010/014; NFR-SEC-003).
 *
 * **The rule under test.** A support request is raised from a scanned machine,
 * so it inherits every gate the scanned workflow already has, and adds one of
 * its own:
 *
 *     the maintenance floor
 *   + the machine is reachable through this technician's work  (ScannedPcAccess)
 *   + the named maintenance record is one they may work        (ScannedWorkTargets)
 *   + the request itself is theirs, or they are an administrator (WorkSupportVisibility)
 *
 * **Nothing the client sends identifies anything.** `technician_id`,
 * `pc_unit_id` and `ticket_id` are derived server-side and there is no field to
 * supply them, so the forgery tests below assert that sending them changes
 * nothing — which is a stronger property than rejecting them.
 *
 * The load-bearing IDOR property: a request absent from a technician's list must
 * be equally unreachable by pasting its uuid. Asserted directly, both ways.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-WSR-01',
        'status' => PcStatus::Available->value,
    ]);

    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-SUPPORT001',
        'status' => QrStatus::Active->value,
    ]);

    $this->job = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
});

/* -------------------------------------------------------------- helpers */

function wsrPayload(array $overrides = []): array
{
    return [
        'explanation' => 'The power supply has failed and there is no spare unit on site.',
        'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 1]],
        ...$overrides,
    ];
}

/** Raise a request as a given user, through the scanned route. */
function raiseRequest(User $user, array $overrides = [], string $code = 'PC-SUPPORT001')
{
    return test()->actingAs($user)
        ->postJson("/api/qr/{$code}/support-requests", wsrPayload($overrides));
}

/* ============================================================== raising */

it('lets a technician raise a request against a machine they are working', function (): void {
    raiseRequest($this->technician)
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.pc_unit.unit_code', 'PC-WSR-01');

    $request = WorkSupportRequest::query()->sole();

    expect($request->technician_id)->toBe($this->technician->id)
        ->and($request->pc_unit_id)->toBe($this->pcUnit->id)
        ->and($request->maintenance_record_id)->toBe($this->job->id);
});

it('refuses an unauthenticated caller', function (): void {
    $this->postJson('/api/qr/PC-SUPPORT001/support-requests', wsrPayload())
        ->assertUnauthorized();

    expect(WorkSupportRequest::query()->count())->toBe(0);
});

it('refuses a teacher', function (): void {
    raiseRequest($this->teacher)->assertForbidden();

    expect(WorkSupportRequest::query()->count())->toBe(0);
});

it('refuses a technician with no work on the scanned machine', function (): void {
    // The floor is cleared — they do maintenance elsewhere — but not here. A
    // valid label does not bridge that gap (Stage C's rule, inherited).
    maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => PcUnit::factory()->create()->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    raiseRequest($this->otherTechnician)->assertForbidden();

    expect(WorkSupportRequest::query()->count())->toBe(0);
});

it('refuses a user stripped of the maintenance floor', function (): void {
    $this->technician->role->permissions()->detach(
        Permission::query()->whereIn('name', ['maintenance.view', 'maintenance.update'])->pluck('id')->all(),
    );
    app(PermissionResolver::class)->forget($this->technician);

    raiseRequest($this->technician->fresh())->assertForbidden();
});

it('refuses a revoked label without naming the machine', function (): void {
    QrCode::query()->where('code', 'PC-SUPPORT001')->update(['status' => QrStatus::Revoked->value]);

    $response = raiseRequest($this->technician)
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_inactive');

    expect($response->getContent())->not->toContain('PC-WSR-01');
});

it('refuses an unknown label', function (): void {
    raiseRequest($this->technician, [], 'PC-NOTHINGHERE')
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_unknown');
});

/* ================================================== forged identifiers */

it('ignores a forged technician_id and records the caller instead', function (): void {
    /*
     * There is no `technician_id` field, so sending one cannot do anything —
     * which is the property worth asserting. A request that merely *rejected*
     * the field would still be one refactor away from honouring it.
     */
    raiseRequest($this->technician, [
        'technician_id' => $this->otherTechnician->id,
        'created_by' => $this->otherTechnician->id,
    ])->assertCreated();

    expect(WorkSupportRequest::query()->sole()->technician_id)->toBe($this->technician->id);
});

it('ignores a forged pc_unit and uses the scanned machine', function (): void {
    $elsewhere = PcUnit::factory()->create(['unit_code' => 'PC-ELSEWHERE']);

    raiseRequest($this->technician, [
        'pc_unit' => $elsewhere->uuid,
        'pc_unit_id' => $elsewhere->id,
    ])->assertCreated();

    expect(WorkSupportRequest::query()->sole()->pc_unit_id)->toBe($this->pcUnit->id);
});

it('refuses a maintenance record belonging to another technician', function (): void {
    $theirs = maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    raiseRequest($this->technician, ['maintenance_id' => $theirs->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');

    expect(WorkSupportRequest::query()->count())->toBe(0);
});

it('refuses a maintenance record on a different machine', function (): void {
    $elsewhere = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => PcUnit::factory()->create()->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    raiseRequest($this->technician, ['maintenance_id' => $elsewhere->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');
});

it('refuses a completed maintenance record', function (): void {
    $closed = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Completed->value,
    ]);

    raiseRequest($this->technician, ['maintenance_id' => $closed->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');
});

it('refuses a cancelled maintenance record', function (): void {
    $cancelled = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Cancelled->value,
    ]);

    raiseRequest($this->technician, ['maintenance_id' => $cancelled->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('maintenance_id');
});

it('refuses an invented maintenance identifier the same way as a forbidden one', function (): void {
    $theirs = maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $invented = raiseRequest($this->technician, [
        'maintenance_id' => '11111111-2222-4333-8444-555555555555',
    ])->assertStatus(422);

    $forbidden = raiseRequest($this->technician, ['maintenance_id' => $theirs->uuid])
        ->assertStatus(422);

    expect($invented->json('errors'))->toBe($forbidden->json('errors'));
});

/* ==================================================== reading: the IDOR */

it('shows a technician only their own requests', function (): void {
    raiseRequest($this->technician)->assertCreated();

    maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    raiseRequest($this->otherTechnician)->assertCreated();

    $mine = $this->actingAs($this->technician)->getJson('/api/work-support-requests')
        ->assertOk()
        ->json('data');

    expect($mine)->toHaveCount(1);
});

it('makes another technicians request equally unreachable by its identifier', function (): void {
    // The load-bearing assertion: absent from the list *and* refused by uuid.
    // A list filter without this check is an IDOR wearing a filter.
    maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    raiseRequest($this->otherTechnician)->assertCreated();

    $theirs = WorkSupportRequest::query()->sole();

    $listed = $this->actingAs($this->technician)->getJson('/api/work-support-requests')->json('data');

    expect($listed)->toHaveCount(0);

    $this->actingAs($this->technician)->getJson("/api/work-support-requests/{$theirs->uuid}")
        ->assertForbidden();
});

it('refuses a teacher the tracking surface entirely', function (): void {
    raiseRequest($this->technician)->assertCreated();
    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->teacher)->getJson('/api/work-support-requests')->assertForbidden();
    $this->actingAs($this->teacher)->getJson("/api/work-support-requests/{$request->uuid}")->assertForbidden();
});

it('lets an administrator reach any request', function (): void {
    raiseRequest($this->technician)->assertCreated();
    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)->getJson("/api/work-support-requests/{$request->uuid}")->assertOk();
    $this->actingAs($this->admin)->getJson("/api/admin/work-support-requests/{$request->uuid}")->assertOk();
});

/* ========================================== the administrative surface */

it('closes the administrator inbox to a technician', function (): void {
    // `maintenance.view` clears the route gate — a Technician holds it — so the
    // refusal must come from the role check, not the middleware.
    raiseRequest($this->technician)->assertCreated();
    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->technician)->getJson('/api/admin/work-support-requests')->assertForbidden();
    $this->actingAs($this->technician)->getJson("/api/admin/work-support-requests/{$request->uuid}")->assertForbidden();
});

it('refuses a technician deciding their own request', function (): void {
    // The self-approval an unguarded workflow eventually permits: a technician
    // asking for a part and granting it to themselves.
    raiseRequest($this->technician)->assertCreated();
    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->technician)
        ->postJson("/api/admin/work-support-requests/{$request->uuid}/approve", [
            'rescheduled_to' => now()->addWeek()->toIso8601String(),
        ])
        ->assertForbidden();

    expect($request->fresh()->status->value)->toBe('submitted');
});

it('refuses a teacher every decision endpoint', function (): void {
    raiseRequest($this->technician)->assertCreated();
    $request = WorkSupportRequest::query()->sole();

    foreach (['approve', 'decline', 'request-clarification', 'close'] as $decision) {
        $this->actingAs($this->teacher)
            ->postJson("/api/admin/work-support-requests/{$request->uuid}/{$decision}", [])
            ->assertForbidden();
    }
});

it('refuses a technician cancelling another technicians request', function (): void {
    maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    raiseRequest($this->otherTechnician)->assertCreated();

    $theirs = WorkSupportRequest::query()->sole();

    $this->actingAs($this->technician)
        ->postJson("/api/work-support-requests/{$theirs->uuid}/cancel", [])
        ->assertForbidden();

    expect($theirs->fresh()->status->value)->toBe('submitted');
});

it('refuses a technician acknowledging another technicians schedule', function (): void {
    maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
    raiseRequest($this->otherTechnician)->assertCreated();

    $theirs = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/work-support-requests/{$theirs->uuid}/approve", [
            'rescheduled_to' => now()->addWeek()->toIso8601String(),
        ])->assertOk();

    $this->actingAs($this->technician)
        ->postJson("/api/work-support-requests/{$theirs->uuid}/acknowledge")
        ->assertForbidden();

    expect($theirs->fresh()->acknowledged_at)->toBeNull();
});
