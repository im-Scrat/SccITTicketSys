<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\WorkSupportRequest;
use App\Models\WorkSupportRequestAttachment;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WP-2.6b Stage F — retrieving evidence attached to a support request
 * (SRS FR-WSR-003/005; NFR-SEC-007/008; SDD DD-45, DD-53).
 *
 * **The rule under test.** An attachment's identifier is not an entitlement.
 * The file is addressed *through* its request, the request is authorized first,
 * and the attachment must belong to it — so a harvested or guessed uuid is
 * refused identically whether it belongs to another request, another
 * technician, or to nothing at all.
 *
 * The second rule: **the stored, server-detected type is authoritative**, and
 * `AttachmentSecurity` alone decides the content type and the disposition. A
 * row whose type is outside the allow-list — including one written before that
 * class existed — must degrade to an opaque download rather than being served
 * as whatever it claims.
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
        'unit_code' => 'PC-ATTACH-01',
        'status' => PcStatus::Available->value,
    ]);

    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-ATTACHTEST',
        'status' => QrStatus::Active->value,
    ]);

    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $this->actingAs($this->technician)
        ->post('/api/qr/PC-ATTACHTEST/support-requests', [
            'explanation' => 'The power supply has failed and there is no spare unit on site.',
            'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 1]],
            'evidence' => [UploadedFile::fake()->image('burnt-psu.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

    $this->request = WorkSupportRequest::query()->sole();
    $this->attachment = WorkSupportRequestAttachment::query()->sole();
});

function attachmentUrl(WorkSupportRequest $request, string $attachmentUuid): string
{
    return "/api/work-support-requests/{$request->uuid}/attachments/{$attachmentUuid}";
}

/* ============================================================= who may read */

it('lets the submitting technician download their own evidence', function (): void {
    $this->actingAs($this->technician)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('lets an administrator download it', function (): void {
    // FR-WSR-005: the decision surface is "not a bare notification". An
    // administrator who cannot open the photograph is deciding blind.
    $this->actingAs($this->admin)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertOk();
});

it('refuses another technician', function (): void {
    $this->actingAs($this->otherTechnician)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertForbidden();
});

it('refuses a teacher', function (): void {
    $this->actingAs($this->teacher)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    /*
     * `beforeEach` signs in to create the fixture, and `actingAs` persists for
     * the whole test instance — so the guards must be forgotten for this to be
     * a genuinely anonymous request rather than a test that silently asserts
     * nothing.
     */
    $this->app['auth']->forgetGuards();

    $this->getJson(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertUnauthorized();
});

/* ====================================== the nesting is the authorization */

it('refuses an attachment reached through a different request', function (): void {
    /*
     * The load-bearing test. The caller owns *both* requests, so this is not an
     * ownership refusal — it is the nesting rule: request B does not contain
     * request A's attachment, and saying so is the only honest answer.
     */
    $this->actingAs($this->technician)
        ->post('/api/qr/PC-ATTACHTEST/support-requests', [
            'explanation' => 'A second, unrelated need on the same machine entirely.',
            'items' => [['description' => 'Thermal paste', 'quantity' => 1]],
        ], ['Accept' => 'application/json'])->assertCreated();

    $other = WorkSupportRequest::query()->where('id', '!=', $this->request->id)->sole();

    $this->actingAs($this->technician)
        ->get(attachmentUrl($other, $this->attachment->uuid))
        ->assertNotFound();
});

it('answers an invented identifier exactly as it answers a real forbidden one', function (): void {
    // Non-disclosure: the endpoint must not confirm that someone else's uuid is
    // a real attachment.
    $this->actingAs($this->technician)
        ->post('/api/qr/PC-ATTACHTEST/support-requests', [
            'explanation' => 'A second, unrelated need on the same machine entirely.',
            'items' => [['description' => 'Thermal paste', 'quantity' => 1]],
        ], ['Accept' => 'application/json'])->assertCreated();

    $other = WorkSupportRequest::query()->where('id', '!=', $this->request->id)->sole();

    $real = $this->actingAs($this->technician)
        ->getJson(attachmentUrl($other, $this->attachment->uuid))
        ->assertNotFound();

    $invented = $this->actingAs($this->technician)
        ->getJson(attachmentUrl($other, (string) Str::uuid()))
        ->assertNotFound();

    /*
     * Same status and same failure mode. The bodies differ only by the uuid the
     * caller themselves sent — which tells them nothing they did not already
     * know — and in production both render the generic 404. What must never
     * differ is whether the attachment *exists somewhere else*, and it does not:
     * route scoping resolves the child through the parent, so a real foreign
     * attachment and an invented one fail at the identical point.
     */
    expect($real->json('exception'))->toBe($invented->json('exception'));

    foreach ([$real, $invented] as $response) {
        expect($response->getContent())
            ->not->toContain($this->request->uuid)
            ->and($response->getContent())->not->toContain('storage_path');
    }
});

it('never lets an attachment be reached without naming a request', function (): void {
    // There is deliberately no flat `/attachments/{uuid}` endpoint.
    $this->actingAs($this->admin)
        ->getJson("/api/attachments/{$this->attachment->uuid}")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->getJson("/api/work-support-request-attachments/{$this->attachment->uuid}")
        ->assertNotFound();
});

/* ================================================== what is actually served */

it('serves an approved image inline with its stored type', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('forces a PDF to download rather than rendering it in the app origin', function (): void {
    // A PDF is a scripting format and an inline viewer runs in this origin, so
    // it is allow-listed for upload and excluded from inline display.
    $this->actingAs($this->technician)
        ->post('/api/qr/PC-ATTACHTEST/support-requests', [
            'explanation' => 'The quotation for the replacement unit is attached here.',
            'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 1]],
            'evidence' => [UploadedFile::fake()->create('quote.pdf', 12, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

    $pdfRequest = WorkSupportRequest::query()->where('id', '!=', $this->request->id)->sole();
    $pdf = WorkSupportRequestAttachment::query()
        ->where('work_support_request_id', $pdfRequest->id)
        ->sole();

    $response = $this->actingAs($this->admin)
        ->get(attachmentUrl($pdfRequest, $pdf->uuid))
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('treats the stored type as authoritative, not the filename', function (): void {
    // The row says PNG; the name says .exe. What is served is decided by the
    // stored, server-detected value.
    $this->attachment->forceFill(['original_filename' => 'payload.exe'])->save();

    $this->actingAs($this->admin)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('degrades a stored type outside the allow-list to an opaque download', function (): void {
    /*
     * A row written before `AttachmentSecurity` existed, or corrupted since.
     * It must not be served as whatever it claims — it becomes an opaque
     * attachment, which is safe whatever the bytes turn out to be.
     */
    $this->attachment->forceFill(['mime_type' => 'text/html'])->save();

    $response = $this->actingAs($this->admin)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');

    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('returns a controlled 404 when the stored file is gone', function (): void {
    Storage::disk('local')->delete($this->attachment->storage_path);

    $this->actingAs($this->admin)
        ->getJson(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertNotFound();
});

/* ============================================================ disclosure */

it('never puts a storage path in any payload', function (): void {
    $body = $this->actingAs($this->admin)
        ->getJson("/api/admin/work-support-requests/{$this->request->uuid}")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('storage_path')
        ->and($body)->not->toContain('work-support/')
        ->and($body)->not->toContain($this->attachment->storage_path);
});

it('adds no new permission to reach evidence', function (): void {
    // §8: reading the evidence is part of reading the request. A technician with
    // no `assets.*` and no invented `wsr.*` reaches their own attachment.
    expect($this->technician->hasPermissionTo('assets.view'))->toBeFalse();

    $this->actingAs($this->technician)
        ->get(attachmentUrl($this->request, $this->attachment->uuid))
        ->assertOk();
});
