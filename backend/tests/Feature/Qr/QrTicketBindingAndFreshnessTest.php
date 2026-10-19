<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Phase 2.9 — the two minimum QR changes (owner decision D-C: the QR stays an
 * identifier; no photo verification).
 *
 *  1. A support request raised with no maintenance record is bound,
 *     server-side, to the caller's ONE workable ticket on that machine —
 *     never to a client-supplied ticket, and to none when there are several.
 *  2. A proof-of-work submission must quote a scan made within
 *     `qr.scan_session_ttl_minutes` (default 120; 0 disables the window).
 */

beforeEach(function (): void {
    Storage::fake('local');
    $this->withHeader('Accept', 'application/json');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->pc = PcUnit::factory()->create(['unit_code' => 'PC-BIND-01', 'status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $this->pc->id,
        'asset_id' => null,
        'code' => 'PC-BINDTEST01',
        'status' => QrStatus::Active->value,
    ]);
});

function workableTicketOn(PcUnit $pc, User $technician, AssignmentStatus $status = AssignmentStatus::Accepted): Ticket
{
    $ticket = ticketFor(userWithRole('teacher'), 'assigned', ['pc_unit_id' => $pc->id]);
    assign($ticket, $technician, $status);

    return $ticket;
}

function raiseSupportRequest(mixed $test, User $as, array $extra = []): TestResponse
{
    return $test->actingAs($as)->post('/api/qr/PC-BINDTEST01/support-requests', [
        'explanation' => 'The machine needs a replacement keyboard before I can test it.',
        'items' => [['description' => 'USB keyboard', 'quantity' => 1]],
        ...$extra,
    ]);
}

/* ----------------------------------------------------- ticket binding */

it('binds a record-less support request to the caller’s one workable ticket on the machine', function (): void {
    $ticket = workableTicketOn($this->pc, $this->technician);

    raiseSupportRequest($this, $this->technician)->assertCreated();

    expect(WorkSupportRequest::query()->sole()->ticket_id)->toBe($ticket->id);
});

it('leaves the ticket unbound when the caller has several workable tickets on the machine', function (): void {
    workableTicketOn($this->pc, $this->technician);
    workableTicketOn($this->pc, $this->technician, AssignmentStatus::InProgress);

    raiseSupportRequest($this, $this->technician)->assertCreated();

    expect(WorkSupportRequest::query()->sole()->ticket_id)->toBeNull();
});

it('ignores another technician’s ticket on the same machine when binding', function (): void {
    $mine = workableTicketOn($this->pc, $this->technician);
    workableTicketOn($this->pc, userWithRole('technician'));

    raiseSupportRequest($this, $this->technician)->assertCreated();

    expect(WorkSupportRequest::query()->sole()->ticket_id)->toBe($mine->id);
});

it('ignores a finished assignment when binding', function (): void {
    $live = workableTicketOn($this->pc, $this->technician);
    workableTicketOn($this->pc, $this->technician, AssignmentStatus::Completed);

    raiseSupportRequest($this, $this->technician)->assertCreated();

    expect(WorkSupportRequest::query()->sole()->ticket_id)->toBe($live->id);
});

it('never takes the ticket from the payload', function (): void {
    $mine = workableTicketOn($this->pc, $this->technician);
    $foreign = ticketFor($this->teacher);

    raiseSupportRequest($this, $this->technician, ['ticket_id' => $foreign->id, 'ticket' => $foreign->uuid])->assertCreated();

    expect(WorkSupportRequest::query()->sole()->ticket_id)->toBe($mine->id);
});

it('still refuses a teacher raising a support request', function (): void {
    raiseSupportRequest($this, $this->teacher)->assertForbidden();

    expect(WorkSupportRequest::query()->count())->toBe(0);
});

/* ------------------------------------------------------ scan freshness */

function bindingScan(mixed $test, User $as): string
{
    return (string) $test->actingAs($as)->postJson('/api/qr/PC-BINDTEST01/scan')->json('scan_id');
}

function bindingProof(mixed $test, User $as, string $scanId): TestResponse
{
    return $test->actingAs($as)->post('/api/qr/PC-BINDTEST01/proof', [
        'scan_id' => $scanId,
        'resolution' => 'Replaced the keyboard and tested every key.',
        'outcome' => 'in_progress',
        'evidence_type' => 'after',
        'evidence' => [UploadedFile::fake()->image('after.jpg')],
    ]);
}

it('accepts proof quoting a recent scan', function (): void {
    maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pc->id, 'status' => 'in_progress']);
    $scanId = bindingScan($this, $this->technician);

    bindingProof($this, $this->technician, $scanId)->assertOk();
});

it('refuses proof quoting a scan older than the window, with the generic scan refusal', function (): void {
    maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pc->id, 'status' => 'in_progress']);
    $scanId = bindingScan($this, $this->technician);
    QrScanLog::query()->where('uuid', $scanId)->update(['scanned_at' => now()->subMinutes(121)]);

    bindingProof($this, $this->technician, $scanId)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scan_id' => 'That scan is no longer valid. Scan the label again to continue.']);
});

it('honours an administrator-configured window', function (): void {
    SystemSetting::query()->where('key', 'qr.scan_session_ttl_minutes')->update(['value' => json_encode(5)]);
    maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pc->id, 'status' => 'in_progress']);
    $scanId = bindingScan($this, $this->technician);
    QrScanLog::query()->where('uuid', $scanId)->update(['scanned_at' => now()->subMinutes(6)]);

    bindingProof($this, $this->technician, $scanId)->assertStatus(422);
});

it('switches the window off when it is set to 0', function (): void {
    SystemSetting::query()->where('key', 'qr.scan_session_ttl_minutes')->update(['value' => json_encode(0)]);
    maintenanceFor($this->technician, 'corrective', ['pc_unit_id' => $this->pc->id, 'status' => 'in_progress']);
    $scanId = bindingScan($this, $this->technician);
    QrScanLog::query()->where('uuid', $scanId)->update(['scanned_at' => now()->subDays(3)]);

    bindingProof($this, $this->technician, $scanId)->assertOk();
});
