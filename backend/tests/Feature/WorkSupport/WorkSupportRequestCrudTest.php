<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\HardwareModel;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\WorkSupportRequest;
use App\Models\WorkSupportRequestAttachment;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * WP-2.6b Stage E — what a work support request must contain, and what the
 * technician gets back (SRS FR-WSR-001/002/003/005/009/010).
 *
 * Authorization lives in `WorkSupportAuthorizationTest` and the transition map
 * in `WorkSupportLifecycleTest`. This file is about **content**: the three
 * things FR-WSR-002 requires on submission, the evidence boundary FR-WSR-003
 * insists on, and the two tracking surfaces.
 */
beforeEach(function (): void {
    Storage::fake('local');

    $this->withHeader('Accept', 'application/json');

    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');

    $this->pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-CRUD-01',
        'status' => PcStatus::Available->value,
    ]);

    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-CRUDTEST01',
        'status' => QrStatus::Active->value,
    ]);

    $this->job = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);
});

function submit(array $overrides = [])
{
    return test()->post('/api/qr/PC-CRUDTEST01/support-requests', [
        'explanation' => 'The power supply has failed and there is no spare unit on site.',
        'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 2]],
        ...$overrides,
    ]);
}

/* ================================================ FR-WSR-002 — content */

it('records the explanation, the items and the job context', function (): void {
    $this->actingAs($this->technician);

    submit()
        ->assertCreated()
        ->assertJsonPath('data.explanation', 'The power supply has failed and there is no spare unit on site.')
        ->assertJsonPath('data.items.0.name', 'ATX power supply, 500W')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.from_catalog', false)
        ->assertJsonPath('data.maintenance.id', $this->job->uuid)
        ->assertJsonPath('data.pc_unit.unit_code', 'PC-CRUD-01');
});

it('refuses a request with no items', function (): void {
    $this->actingAs($this->technician);

    submit(['items' => []])->assertStatus(422)->assertJsonValidationErrors('items');

    expect(WorkSupportRequest::query()->count())->toBe(0);
});

it('refuses a request with no explanation', function (): void {
    $this->actingAs($this->technician);

    submit(['explanation' => ''])->assertStatus(422)->assertJsonValidationErrors('explanation');
});

it('refuses an explanation that is only whitespace', function (): void {
    $this->actingAs($this->technician);

    submit(['explanation' => "   \t\n   "])->assertStatus(422)->assertJsonValidationErrors('explanation');
});

it('refuses a line item that names neither a catalog model nor a description', function (): void {
    // The database CHECK in words, so the technician learns which line is at
    // fault rather than meeting a constraint violation.
    $this->actingAs($this->technician);

    submit(['items' => [['quantity' => 1]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.description');
});

it('refuses a non-positive quantity', function (): void {
    $this->actingAs($this->technician);

    submit(['items' => [['description' => 'Fan', 'quantity' => 0]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.quantity');
});

it('accepts a catalog model instead of free text', function (): void {
    $model = HardwareModel::factory()->create(['model_name' => 'Corsair RM550x']);

    $this->actingAs($this->technician);

    submit(['items' => [['hardware_model' => $model->id, 'quantity' => 1]]])
        ->assertCreated()
        ->assertJsonPath('data.items.0.name', 'Corsair RM550x')
        ->assertJsonPath('data.items.0.from_catalog', true);
});

it('refuses a catalog model that does not exist', function (): void {
    $this->actingAs($this->technician);

    submit(['items' => [['hardware_model' => 999999, 'quantity' => 1]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.hardware_model');
});

it('takes several items at once', function (): void {
    $this->actingAs($this->technician);

    submit(['items' => [
        ['description' => 'ATX power supply, 500W', 'quantity' => 1],
        ['description' => 'Thermal paste', 'quantity' => 2, 'remarks' => 'Any brand'],
    ]])->assertCreated()->assertJsonCount(2, 'data.items');

    expect(WorkSupportRequest::query()->sole()->items()->count())->toBe(2);
});

/* ============================================ FR-WSR-003 — evidence */

it('stores optional evidence through the single attachment boundary', function (): void {
    $this->actingAs($this->technician);

    submit(['evidence' => [UploadedFile::fake()->image('burnt-psu.jpg')]])
        ->assertCreated()
        ->assertJsonCount(1, 'data.attachments')
        ->assertJsonPath('data.attachments.0.kind', 'image');

    $file = WorkSupportRequestAttachment::query()->sole();

    expect($file->disk)->toBe('local')
        ->and($file->mime_type)->toBe('image/jpeg')
        ->and($file->checksum)->toHaveLength(64)
        ->and($file->file_size)->toBeGreaterThan(0)
        ->and($file->uploaded_by)->toBe($this->technician->id);

    Storage::disk('local')->assertExists($file->storage_path);
});

it('never trusts the client-declared MIME type on evidence', function (): void {
    // A real file whose bytes are not an image — the testing fake cannot
    // disagree with its own declaration, so it would prove nothing here.
    $path = sys_get_temp_dir().'/sccit-wsr-not-an-image.jpg';
    file_put_contents($path, 'MZ this is not a JPEG at all');

    $this->actingAs($this->technician);

    submit(['evidence' => [new UploadedFile($path, 'evidence.jpg', 'image/jpeg', null, true)]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('evidence.0');

    expect(WorkSupportRequestAttachment::query()->count())->toBe(0)
        ->and(WorkSupportRequest::query()->count())->toBe(0);
});

it('exposes no storage path for an attachment', function (): void {
    // The bytes are reachable only through an authorized route; a path in the
    // payload would be a way around that.
    $this->actingAs($this->technician);

    $body = submit(['evidence' => [UploadedFile::fake()->image('psu.jpg')]])
        ->assertCreated()
        ->getContent();

    expect($body)->not->toContain('storage_path')
        ->and($body)->not->toContain('work-support/');
});

/* ==================================== FR-WSR-001 — a request with no job */

it('accepts a request that names no maintenance record', function (): void {
    // FR-WSR-001: "a request unrelated to any ticket shall still be permitted,
    // provided it names a PC unit." Reached here through a ticket assignment,
    // which is what makes the machine reachable with no maintenance record.
    $pc = PcUnit::factory()->create(['unit_code' => 'PC-NOJOB-01', 'status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $pc->id,
        'asset_id' => null,
        'code' => 'PC-NOJOBHERE1',
        'status' => QrStatus::Active->value,
    ]);

    $ticket = ticketFor(userWithRole('teacher'), 'open', ['pc_unit_id' => $pc->id]);
    $ticket->assignments()->create([
        'technician_id' => $this->technician->id,
        'assigned_by' => $this->admin->id,
        'status' => AssignmentStatus::Accepted->value,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-NOJOBHERE1/support-requests', [
            'explanation' => 'The machine needs a replacement keyboard before I can test it.',
            'items' => [['description' => 'USB keyboard', 'quantity' => 1]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.maintenance', null)
        ->assertJsonPath('data.pc_unit.unit_code', 'PC-NOJOB-01');
});

/* ================================= FR-WSR-009/010 — the two surfaces */

it('puts undecided requests at the top of the technicians tracking page', function (): void {
    // A tracking page ordered purely by date buries the open question under a
    // month of settled ones.
    $this->actingAs($this->technician);

    submit()->assertCreated();
    $first = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/work-support-requests/{$first->uuid}/decline", [
            'decline_reason' => 'No budget this term, sorry.',
        ])->assertOk();

    $this->actingAs($this->technician);
    submit(['explanation' => 'A second thing is needed before this job can finish at all.'])->assertCreated();

    $statuses = $this->actingAs($this->technician)
        ->getJson('/api/work-support-requests')
        ->assertOk()
        ->json('data.*.status');

    expect($statuses)->toBe(['submitted', 'declined']);
});

it('filters the technicians page by status', function (): void {
    $this->actingAs($this->technician);
    submit()->assertCreated();

    $this->getJson('/api/work-support-requests?status=submitted')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/work-support-requests?status=declined')->assertOk()->assertJsonCount(0, 'data');
});

it('gives the administrator inbox a pending filter drawn from the enum', function (): void {
    $this->actingAs($this->technician);
    submit()->assertCreated();
    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin);

    $this->getJson('/api/admin/work-support-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/admin/work-support-requests/{$request->uuid}/request-clarification", [
        'clarification_reason' => 'Come and walk me through this one.',
    ])->assertOk();

    // `clarification_requested` is still undecided, so it stays in the inbox.
    $this->getJson('/api/admin/work-support-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/admin/work-support-requests/{$request->uuid}/decline", [
        'decline_reason' => 'No budget this term, sorry.',
    ])->assertOk();

    $this->getJson('/api/admin/work-support-requests?status=pending')->assertOk()->assertJsonCount(0, 'data');
});

it('shows the administrator everything needed to decide', function (): void {
    // FR-WSR-005: "not a bare notification" — the submitting technician, the
    // machine, the job, the items and the explanation, in one payload.
    $this->actingAs($this->technician);
    submit(['evidence' => [UploadedFile::fake()->image('psu.jpg')]])->assertCreated();

    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)
        ->getJson("/api/admin/work-support-requests/{$request->uuid}")
        ->assertOk()
        ->assertJsonPath('data.technician.name', $this->technician->fullName())
        ->assertJsonPath('data.pc_unit.unit_code', 'PC-CRUD-01')
        ->assertJsonPath('data.maintenance.title', $this->job->title)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonCount(1, 'data.attachments')
        ->assertJsonPath('data.explanation', 'The power supply has failed and there is no spare unit on site.');
});
