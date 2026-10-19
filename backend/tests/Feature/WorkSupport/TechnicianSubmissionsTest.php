<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * WP-2.6b Stage F — the technician's combined submission history
 * (SRS FR-WSR-009; NFR-SEC-003).
 *
 * > *"…tracking **everything they personally submitted** — proof-of-work
 * >  records **and** support requests … A technician shall reach their own
 * >  records and no others, in lists **and** by direct identifier."*
 *
 * **The rule under test.** The feed composes two existing ownership scopes and
 * defines no third. So the assertions that matter are the negative ones: another
 * technician's proof of work and another technician's support request must both
 * be absent, and absent for the *same* reason they are unreachable directly —
 * because the module that owns each rule said so, not because this endpoint
 * remembered to filter.
 *
 * The second property: a maintenance record the technician **owns but never
 * submitted proof against** is a job, not a submission, and must not appear. A
 * feed that listed every owned record would quietly become a second maintenance
 * queue with a different name.
 */
beforeEach(function (): void {
    Storage::fake('local');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-FEED-01',
        'status' => PcStatus::Available->value,
    ]);

    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-FEEDTEST01',
        'status' => QrStatus::Active->value,
    ]);
});

/* -------------------------------------------------------------- helpers */

/** Give a technician open work on the shared machine. */
function feedJob(User $technician, PcUnit $pcUnit): MaintenanceRecord
{
    return maintenanceFor($technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
}

/** Submit proof of work as this user, through the real endpoints. */
function submitProof(User $user, string $code = 'PC-FEEDTEST01'): void
{
    $scanId = (string) test()->actingAs($user)->postJson("/api/qr/{$code}/scan")->json('scan_id');

    test()->actingAs($user)->post("/api/qr/{$code}/proof", [
        'scan_id' => $scanId,
        'resolution' => 'Replaced the power supply and retested the machine.',
        'outcome' => 'in_progress',
        'evidence_type' => 'after',
        'evidence' => [UploadedFile::fake()->image('after.jpg')],
    ], ['Accept' => 'application/json'])->assertOk();
}

/** Raise a support request as this user. */
function raiseSupport(User $user, string $code = 'PC-FEEDTEST01'): void
{
    test()->actingAs($user)->post("/api/qr/{$code}/support-requests", [
        'explanation' => 'The power supply has failed and there is no spare unit on site.',
        'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 1]],
    ], ['Accept' => 'application/json'])->assertCreated();
}

function feed(User $user, string $query = '')
{
    return test()->actingAs($user)->getJson("/api/technician/submissions{$query}");
}

/* =========================================================== both kinds */

it('lists a technicians proof of work and their support requests together', function (): void {
    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);
    raiseSupport($this->technician);

    $kinds = feed($this->technician)->assertOk()->json('data.*.kind');

    expect($kinds)->toHaveCount(2)
        ->and($kinds)->toEqualCanonicalizing(['proof_of_work', 'support_request']);
});

it('identifies each entry by kind and carries the right body', function (): void {
    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);
    raiseSupport($this->technician);

    $data = collect(feed($this->technician)->assertOk()->json('data'));

    $proof = $data->firstWhere('kind', 'proof_of_work');
    $support = $data->firstWhere('kind', 'support_request');

    expect($proof)->toHaveKey('proof_of_work')
        ->and($proof['proof_of_work']['pc_unit']['unit_code'])->toBe('PC-FEED-01')
        ->and($proof['proof_of_work']['resolution'])->toContain('Replaced the power supply')
        ->and($proof['proof_of_work']['evidence'])->toHaveCount(1)
        ->and($support)->toHaveKey('support_request')
        ->and($support['support_request']['status'])->toBe('submitted');
});

it('orders newest first', function (): void {
    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);

    $this->travel(5)->minutes();
    raiseSupport($this->technician);
    $this->travelBack();

    expect(feed($this->technician)->assertOk()->json('data.0.kind'))->toBe('support_request');
});

it('filters by kind', function (): void {
    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);
    raiseSupport($this->technician);

    expect(feed($this->technician, '?kind=proof_of_work')->assertOk()->json('data.*.kind'))
        ->toBe(['proof_of_work'])
        ->and(feed($this->technician, '?kind=support_request')->assertOk()->json('data.*.kind'))
        ->toBe(['support_request']);
});

it('refuses a kind it does not know', function (): void {
    feed($this->technician, '?kind=invoices')->assertStatus(422)->assertJsonValidationErrors('kind');
});

/* ================================================== a job is not a submission */

it('omits an owned maintenance record that was never submitted against', function (): void {
    // Scheduled for them by an administrator, never scanned. It is work, not a
    // submission — a feed that listed it would be the maintenance queue again.
    feedJob($this->technician, $this->pcUnit);

    expect(feed($this->technician)->assertOk()->json('data'))->toHaveCount(0);
});

/* ==================================================== technician isolation */

it('excludes another technicians proof of work', function (): void {
    feedJob($this->otherTechnician, $this->pcUnit);
    submitProof($this->otherTechnician);

    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);

    $data = feed($this->technician)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['proof_of_work']['resolution'])->not->toBeNull();

    // And the other technician sees exactly their own — one each, not two.
    expect(feed($this->otherTechnician)->assertOk()->json('data'))->toHaveCount(1);
});

it('excludes another technicians support request', function (): void {
    feedJob($this->otherTechnician, $this->pcUnit);
    raiseSupport($this->otherTechnician);

    feedJob($this->technician, $this->pcUnit);
    raiseSupport($this->technician);

    $mine = feed($this->technician)->assertOk()->json('data');

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['support_request']['technician']['name'])->toBe($this->technician->fullName());
});

it('keeps a record absent from the feed unreachable by its identifier', function (): void {
    /*
     * The load-bearing equivalence. Whatever the feed excludes must also be
     * refused directly — a list filter without this check is an IDOR wearing a
     * filter (FR-WSR-009, FR-MNT-011).
     */
    feedJob($this->otherTechnician, $this->pcUnit);
    submitProof($this->otherTechnician);
    raiseSupport($this->otherTechnician);

    expect(feed($this->technician)->assertOk()->json('data'))->toHaveCount(0);

    $theirRecord = MaintenanceRecord::query()->where('technician_id', $this->otherTechnician->id)->sole();
    $theirRequest = WorkSupportRequest::query()->sole();

    $this->actingAs($this->technician)
        ->getJson("/api/maintenance/{$theirRecord->uuid}")->assertForbidden();

    $this->actingAs($this->technician)
        ->getJson("/api/work-support-requests/{$theirRequest->uuid}")->assertForbidden();
});

it('gives an administrator their own submissions, not the estate', function (): void {
    // "Everything I submitted" must mean the same thing to both roles, or the
    // page lies to exactly one of them. The estate views live elsewhere.
    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);
    raiseSupport($this->technician);

    expect(feed($this->admin)->assertOk()->json('data'))->toHaveCount(0);
});

/* ========================================================= role boundary */

it('refuses a teacher', function (): void {
    feed($this->teacher)->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/technician/submissions')->assertUnauthorized();
});

/* ============================================================ disclosure */

it('leaks no field the scan-scoped model withholds', function (): void {
    /*
     * The feed must not become a wider projection than the panel that produced
     * the submission (DD-49/OD-7). Asserted against the encoded payload with
     * planted values, so a field cannot reappear through a later resource
     * change without failing here.
     */
    $this->pcUnit->forceFill([
        'serial_number' => 'SERIAL-LEAK-CHECK',
        'asset_tag' => 'TAG-LEAK-CHECK',
        'hostname' => 'HOST-LEAK-CHECK',
        'ip_address' => '10.55.55.55',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
    ])->save();

    feedJob($this->technician, $this->pcUnit);
    submitProof($this->technician);
    raiseSupport($this->technician);

    $body = feed($this->technician)->assertOk()->getContent();

    foreach ([
        'SERIAL-LEAK-CHECK', 'TAG-LEAK-CHECK', 'HOST-LEAK-CHECK',
        '10.55.55.55', 'AA:BB:CC:DD:EE:FF',
        'storage_path', 'serial_number', 'asset_tag', 'hostname',
        'ip_address', 'mac_address', 'cost', 'labor_hours',
    ] as $secret) {
        expect($body)->not->toContain($secret);
    }
});
