<?php

declare(strict_types=1);

use App\Domains\Assets\Services\ScannedPcAccess;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\QrCode;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;

/**
 * WP-2.6b Stage C — the scan-scoped panel's authorization contract
 * (SRS FR-QR-012, FR-AST-013; AC-QR-012; SDD DD-49, DD-38, DD-41).
 *
 * **The rule under test.** A scan identifies a machine. Authentication
 * identifies a person. Authorization — and only authorization — decides whether
 * that person may open that machine's panel:
 *
 *     permission floor  +  the machine is reachable through their assigned work
 *
 * Possession of the code contributes nothing. Every test below that refuses
 * access refuses it while holding a perfectly valid, active code.
 *
 * **The load-bearing property** is the equivalence between the two arms of
 * `ScannedPcAccess`: whatever `scope()` excludes from the reachable set,
 * `canReach()` must refuse for a single record, and the HTTP layer must refuse
 * by uuid. That is asserted directly rather than assumed, because two
 * definitions of "reachable" would eventually disagree and the disagreement
 * would be an IDOR.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->room = Room::factory()->create(['name' => 'Laboratory 4']);

    $this->pcUnit = PcUnit::factory()->create([
        'room_id' => $this->room->id,
        'unit_code' => 'PC-LAB4-01',
        'pc_name' => 'Lab 4 Workstation 1',
        'status' => PcStatus::Available->value,
    ]);

    $this->qr = QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-PANELTEST1',
        'status' => QrStatus::Active->value,
    ]);

    $this->access = app(ScannedPcAccess::class);
});

/** Give a technician open maintenance on a machine — the primary reachability arm. */
function workOn(PcUnit $pcUnit, User $technician, string $status = 'in_progress')
{
    return maintenanceFor($technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => $status,
    ]);
}

function panel(string $code = 'PC-PANELTEST1')
{
    return test()->getJson("/api/qr/{$code}/panel");
}

/* ============================================================ role boundary */

it('lets a technician with open maintenance on the machine open the panel', function (): void {
    workOn($this->pcUnit, $this->technician);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->assertJsonPath('data.unit_code', 'PC-LAB4-01')
        ->assertJsonPath('data.pc_name', 'Lab 4 Workstation 1')
        ->assertJsonPath('data.location.room', 'Laboratory 4');
});

it('refuses a technician with no work on the machine', function (): void {
    // Holds the full `maintenance.*` set and a valid active code — and is still
    // refused, because the code is not the authorization (DD-47).
    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('refuses a technician whose work is on a different machine', function (): void {
    $otherPc = PcUnit::factory()->create();
    workOn($otherPc, $this->technician);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('refuses a technician when the work belongs to another technician', function (): void {
    // AC-QR-012's core case: the machine is being worked, just not by them.
    workOn($this->pcUnit, $this->otherTechnician);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('refuses a teacher', function (): void {
    workOn($this->pcUnit, $this->technician);

    $this->actingAs($this->teacher)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('lets an administrator open any panel unrestricted', function (): void {
    // FR-QR-012: "an Administrator's is unrestricted" — no work required.
    $this->actingAs($this->admin)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->assertJsonPath('data.unit_code', 'PC-LAB4-01');
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/qr/PC-PANELTEST1/panel')->assertUnauthorized();
});

/* ====================================================== reachability arms */

it('reaches a machine through an active ticket assignment', function (): void {
    // The second arm of the predicate: no maintenance record, but an open
    // ticket on this machine actively assigned to this technician.
    $ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pcUnit->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk();
});

it('does not reach a machine through a closed ticket', function (): void {
    $ticket = ticketFor($this->teacher, 'closed', ['pc_unit_id' => $this->pcUnit->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('does not reach a machine through a finished assignment', function (): void {
    /*
     * `READABLE_ASSIGNMENT_STATUSES` keeps a completed job readable on the
     * ticket page, because a technician needs their own history. The panel is an
     * on-site working surface, so it uses the *writable* set: finishing the job
     * ends the entitlement to stand at the machine.
     */
    $ticket = ticketFor($this->teacher, 'in-progress', ['pc_unit_id' => $this->pcUnit->id]);
    assign($ticket, $this->technician, AssignmentStatus::Completed);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('does not reach a machine through completed maintenance', function (string $status): void {
    // Only `MaintenanceVisibility::OPEN_STATUSES` grant reach. A finished or
    // cancelled visit is history, not present work.
    workOn($this->pcUnit, $this->technician, $status);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
})->with([MaintenanceStatus::Completed->value, MaintenanceStatus::Cancelled->value]);

it('reaches a machine through maintenance the technician opened but was not assigned', function (): void {
    // FR-MNT-011 is "assigned to me OR created by me", and this delegates to
    // MaintenanceVisibility rather than restating it — so both halves hold here
    // without this class knowing the rule.
    maintenanceFor($this->otherTechnician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'created_by' => $this->technician->id,
    ]);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk();
});

it('handles multiple active maintenance records on one machine', function (): void {
    /*
     * WP-2.6 deliberately permits concurrent records — a scheduled preventive
     * visit alongside an active corrective repair. The panel must show the
     * caller's own records and no one else's.
     */
    workOn($this->pcUnit, $this->technician, MaintenanceStatus::InProgress->value);
    workOn($this->pcUnit, $this->technician, MaintenanceStatus::Scheduled->value);
    workOn($this->pcUnit, $this->otherTechnician, MaintenanceStatus::InProgress->value);

    $payload = $this->actingAs($this->technician)
        ->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->json('data.active_maintenance');

    expect($payload)->toHaveCount(2);
});

/* ================================================= permission boundary */

it('refuses a user stripped of both floor permissions', function (): void {
    // Permission boundary, independent of role: revoke the floor and the panel
    // closes even though the maintenance record still exists.
    workOn($this->pcUnit, $this->technician);

    $this->technician->role->permissions()->detach(
        Permission::query()
            ->whereIn('name', ['maintenance.view', 'tickets.update'])
            ->pluck('id')
            ->all(),
    );
    app(PermissionResolver::class)->forget($this->technician);

    $this->actingAs($this->technician->fresh())->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('never requires an assets permission', function (): void {
    // AC-QR-012 / FR-AST-013: the panel is reachable with no `assets.*` at all,
    // and the Asset Management module stays shut for the same machine.
    workOn($this->pcUnit, $this->technician);

    expect($this->technician->hasPermissionTo('assets.view'))->toBeFalse();

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')->assertOk();

    $this->actingAs($this->technician)
        ->getJson("/api/admin/pc-units/{$this->pcUnit->uuid}")
        ->assertForbidden();
});

it('does not introduce any qr or wsr permission', function (): void {
    // The seeded matrix is the Client's; WP-2.6b adds nothing to it.
    expect(Permission::query()->where('name', 'LIKE', 'qr.%')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'LIKE', 'wsr.%')->exists())->toBeFalse();
});

/* ============================================== scan-bound / QR state */

it('refuses a revoked label even to a technician with work on the machine', function (): void {
    workOn($this->pcUnit, $this->technician);
    $this->qr->forceFill(['status' => QrStatus::Revoked->value])->save();

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_inactive');
});

it('refuses an unknown label', function (): void {
    workOn($this->pcUnit, $this->technician);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-NOSUCHCODE9/panel')
        ->assertForbidden()
        ->assertJsonPath('reason', 'label_unknown');
});

it('refuses an archived machine', function (): void {
    workOn($this->pcUnit, $this->technician);
    $this->pcUnit->delete();

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('gives a caller without the floor one flat refusal for every cause', function (): void {
    // The non-disclosure rule, restated on this endpoint because it is
    // reachable on its own: a teacher must not learn which codes exist.
    $real = $this->actingAs($this->teacher)->getJson('/api/qr/PC-PANELTEST1/panel')->assertForbidden();
    $fake = $this->actingAs($this->teacher)->getJson('/api/qr/PC-NOSUCHCODE9/panel')->assertForbidden();

    expect($real->json())->toBe($fake->json())
        ->and($real->json('reason'))->toBe('not_authorized');
});

it('treats direct access exactly like a scan', function (): void {
    /*
     * FR-QR-013: "a code shall never be accepted as proof that the scanner is
     * standing in front of the machine." There is therefore nothing to bypass —
     * a caller who never hit /scan gets precisely the same answer.
     */
    workOn($this->pcUnit, $this->technician);

    $withoutScanning = $this->actingAs($this->technician)
        ->getJson('/api/qr/PC-PANELTEST1/panel')->assertOk()->json('data');

    $this->actingAs($this->technician)->postJson('/api/qr/PC-PANELTEST1/scan')->assertOk();

    $afterScanning = $this->actingAs($this->technician)
        ->getJson('/api/qr/PC-PANELTEST1/panel')->assertOk()->json('data');

    expect($withoutScanning)->toBe($afterScanning);
});

/* ============================================================ IDOR */

it('keeps the reachable set and the single-record answer identical', function (): void {
    /*
     * The equivalence, asserted at the service layer where it is defined. Every
     * machine excluded from `scope()` must be refused by `canReach()`, for every
     * actor — otherwise a uuid would reach what a list would not.
     */
    $reachable = PcUnit::factory()->create();
    $unreachable = PcUnit::factory()->create();
    $othersWork = PcUnit::factory()->create();

    workOn($reachable, $this->technician);
    workOn($othersWork, $this->otherTechnician);

    $scoped = $this->access->scope(PcUnit::query(), $this->technician)->pluck('id')->all();

    foreach ([$reachable, $unreachable, $othersWork, $this->pcUnit] as $unit) {
        expect($this->access->canReach($this->technician, $unit))
            ->toBe(in_array($unit->id, $scoped, true), "mismatch for PC {$unit->unit_code}");
    }

    expect($scoped)->toBe([$reachable->id]);
});

it('does not let a pc uuid reach a panel it has no work on', function (): void {
    // The identifier is not the control. Even holding a valid code for machine A
    // and a maintenance record on machine B, machine A stays shut.
    $mine = PcUnit::factory()->create();
    workOn($mine, $this->technician);

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertForbidden();
});

it('refuses every other technician a panel on the same machine', function (): void {
    workOn($this->pcUnit, $this->technician);

    $third = userWithRole('technician');

    $this->actingAs($this->technician)->getJson('/api/qr/PC-PANELTEST1/panel')->assertOk();
    $this->actingAs($this->otherTechnician)->getJson('/api/qr/PC-PANELTEST1/panel')->assertForbidden();
    $this->actingAs($third)->getJson('/api/qr/PC-PANELTEST1/panel')->assertForbidden();
});

/* ================================================== resource exposure */

it('exposes only the approved operational fields', function (): void {
    workOn($this->pcUnit, $this->technician);

    $data = $this->actingAs($this->technician)
        ->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([
        'id', 'unit_code', 'pc_name', 'brand', 'model',
        'location', 'status', 'status_label', 'condition', 'condition_label',
        'specification', 'installed_components',
        'active_tickets', 'active_maintenance', 'qr',
    ]);
});

it('exposes no administrator-only asset information', function (): void {
    /*
     * AC-QR-012, asserted against the **encoded payload** per CLAUDE.md §6 — so
     * a field cannot leak back in through a later change to any resource,
     * relation or model without failing here.
     *
     * The values are made deliberately distinctive so a match cannot be a
     * coincidence of formatting.
     */
    $this->pcUnit->forceFill([
        'serial_number' => 'SECRETSERIAL123',
        'asset_tag' => 'SECRETASSETTAG',
        'hostname' => 'secret-hostname',
        'ip_address' => '10.11.12.13',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'notes' => 'SECRETADMINNOTE',
        'purchase_date' => '2024-01-15',
        'warranty_expiration' => '2027-01-15',
    ])->save();

    workOn($this->pcUnit, $this->technician);

    $body = $this->actingAs($this->technician)
        ->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->getContent();

    foreach ([
        'SECRETSERIAL123',      // serial number
        'SECRETASSETTAG',       // asset tag
        'secret-hostname',      // hostname
        '10.11.12.13',          // IP address
        'AA:BB:CC:DD:EE:FF',    // MAC address
        'SECRETADMINNOTE',      // administrative notes
        '2027-01-15',           // warranty expiration
        '2024-01-15',           // purchase date
        'PC-PANELTEST1',        // the code itself is never echoed back
    ] as $secret) {
        expect($body)->not->toContain($secret);
    }

    // And no key exists for the excluded families, whatever their value.
    foreach ([
        'price', 'purchase', 'supplier', 'warranty', 'custodian', 'assigned_technician',
        'audit', 'attachments', 'created_by', 'updated_by', 'archived',
        'serial_number', 'asset_tag', 'hostname', 'ip_address', 'mac_address', 'notes',
    ] as $key) {
        expect($body)->not->toContain('"'.$key.'"');
    }
});

it('shows only the caller\'s own work on a shared machine', function (): void {
    $mine = workOn($this->pcUnit, $this->technician);
    $theirs = workOn($this->pcUnit, $this->otherTechnician);

    $body = $this->actingAs($this->technician)
        ->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->getContent();

    expect($body)->toContain($mine->uuid)
        ->and($body)->not->toContain($theirs->uuid);
});

it('gives an administrator every active record on the machine', function (): void {
    $mine = workOn($this->pcUnit, $this->technician);
    $theirs = workOn($this->pcUnit, $this->otherTechnician);

    $ids = collect($this->actingAs($this->admin)
        ->getJson('/api/qr/PC-PANELTEST1/panel')
        ->assertOk()
        ->json('data.active_maintenance'))->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$mine->uuid, $theirs->uuid]);
});
