<?php

declare(strict_types=1);

use App\Models\Ticket;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 2.9 — photos while reporting (SDD DD-80). The report form creates the
 * ticket first, then uploads each photo through the one attachment boundary
 * (DD-45), so a failed photo can never lose the report.
 *
 * The server's side of that contract, pinned here:
 *  - the options payload tells the form the real per-file ceiling;
 *  - an upload right after creation works, with real image bytes;
 *  - a refused upload leaves the ticket exactly as it was;
 *  - the extension rule reads the *filename*, which is why the client renames
 *    every prepared photo to the extension of its real type;
 *  - uploads are throttled per account.
 *
 * Real bytes throughout, made with GD — `UploadedFile::fake()->image()`'s temp
 * file is gone by the time the MIME guesser reads it.
 */

/** Real image bytes of the given kind and size, made with GD. */
function reportPhotoBytes(string $kind = 'png', int $width = 2, int $height = 2, bool $noisy = false): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($noisy) {
        // Random pixels do not compress, so the file size tracks the area.
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
    }

    ob_start();
    $kind === 'jpeg' ? imagejpeg($image, null, 90) : imagepng($image);

    return (string) ob_get_clean();
}

function reportPhotoUpload(string $filename, string $bytes, string $declaredMime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $filename, $declaredMime, null, true);
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    Storage::fake('local');
    // Creation dispatches the AI pre-screening; nothing here is about it.
    Queue::fake();

    $this->teacher = userWithRole('teacher');
});

it('tells the form the per-file ceiling the server enforces', function () {
    $this->actingAs($this->teacher)->getJson('/api/tickets/options')
        ->assertOk()->assertJsonPath('data.uploads.max_kb', 10240);

    config(['security.uploads.max_kb' => 2048]);

    $this->actingAs($this->teacher)->getJson('/api/tickets/options')
        ->assertOk()->assertJsonPath('data.uploads.max_kb', 2048);
});

it('attaches a photo to a ticket right after it is reported', function () {
    $uuid = $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Monitor shows vertical lines',
        'description' => 'Coloured vertical lines appear across the whole screen.',
        'category' => 'hardware',
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->teacher)->post(
        "/api/tickets/{$uuid}/attachments",
        ['file' => reportPhotoUpload('IMG_0001.png', reportPhotoBytes(), 'image/png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    expect(Ticket::query()->where('uuid', $uuid)->value('attachment_count'))->toBe(1);

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$uuid}/attachments")
        ->assertOk()
        ->assertJsonPath('data.0.is_image', true)
        ->assertJsonPath('data.0.mime_type', 'image/png');
});

it('refuses an oversized photo and leaves the ticket as it was', function () {
    $ticket = ticketFor($this->teacher);
    config(['security.uploads.max_kb' => 10]);

    $bytes = reportPhotoBytes('png', 200, 200, noisy: true);
    expect(strlen($bytes))->toBeGreaterThan(10 * 1024);

    $this->actingAs($this->teacher)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => reportPhotoUpload('IMG_0002.png', $bytes, 'image/png')],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()->assertJsonValidationErrors('file');

    expect($ticket->fresh())->not->toBeNull()
        ->and($ticket->fresh()->attachment_count)->toBe(0)
        ->and($ticket->attachments()->count())->toBe(0);
});

it('refuses a real JPEG still named .heic — which is why the client renames', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => reportPhotoUpload('IMG_0003.heic', reportPhotoBytes('jpeg'), 'image/jpeg')],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()->assertJsonValidationErrors('file');

    // The same bytes under their real extension are fine.
    $this->actingAs($this->teacher)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => reportPhotoUpload('IMG_0003.jpg', reportPhotoBytes('jpeg'), 'image/jpeg')],
        ['Accept' => 'application/json'],
    )->assertCreated();
});

it('throttles uploads at sixty an hour per account', function () {
    $ticket = ticketFor($this->teacher);
    $bytes = reportPhotoBytes();

    foreach (range(1, 60) as $i) {
        $this->actingAs($this->teacher)->post(
            "/api/tickets/{$ticket->uuid}/attachments",
            ['file' => reportPhotoUpload("IMG_{$i}.png", $bytes, 'image/png')],
            ['Accept' => 'application/json'],
        )->assertCreated();
    }

    $this->actingAs($this->teacher)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => reportPhotoUpload('IMG_61.png', $bytes, 'image/png')],
        ['Accept' => 'application/json'],
    )->assertTooManyRequests();

    expect($ticket->fresh()->attachment_count)->toBe(60);
});
