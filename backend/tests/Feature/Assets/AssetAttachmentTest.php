<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetAttachment;
use App\Models\PcUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
    Storage::fake('local');
});

it('uploads an image and derives its kind from the detected type', function () {
    $asset = Asset::factory()->create();

    $response = $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('front.jpg'), 'caption' => 'Front panel'],
        ['Accept' => 'application/json'],
    )->assertCreated();

    expect($response->json('data.kind'))->toBe('image')
        ->and($response->json('data.is_image'))->toBeTrue()
        ->and($response->json('data.filename'))->toBe('front.jpg')
        ->and($response->json('data.caption'))->toBe('Front panel');

    $attachment = AssetAttachment::query()->firstOrFail();

    expect($attachment->asset_id)->toBe($asset->id)
        ->and($attachment->pc_unit_id)->toBeNull()
        ->and($attachment->checksum)->not->toBeNull();

    // The stored name is generated, never the client's — a client-supplied name
    // is the classic traversal / double-extension vector.
    expect($attachment->storage_path)->not->toContain('front.jpg')
        ->and($attachment->storage_path)->toStartWith("assets/{$asset->uuid}/");

    Storage::disk('local')->assertExists($attachment->storage_path);
});

it('never exposes the storage path to the client', function () {
    $asset = Asset::factory()->create();

    $response = $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('secret.png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $body = json_encode($response->json());

    expect($body)->not->toContain('storage_path')
        ->and($body)->not->toContain('/var/www')
        // What the client gets is a route it may call, not a location on disk.
        ->and($response->json('data.url'))->toContain('/admin/asset-attachments/');
});

it('accepts a PDF document and classifies it as a document', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->create('warranty.pdf', 64, 'application/pdf')],
        ['Accept' => 'application/json'],
    )->assertCreated()->assertJsonPath('data.kind', 'document');
});

it('rejects a disallowed file type whatever its extension claims', function () {
    $asset = Asset::factory()->create();

    // A script renamed to look like an image is rejected on its detected type.
    $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->create('payload.png', 8, 'application/x-httpd-php')],
        ['Accept' => 'application/json'],
    )->assertStatus(422)->assertJsonValidationErrors('file');

    expect(AssetAttachment::query()->count())->toBe(0);
});

it('rejects a file over the configured size ceiling', function () {
    config(['security.uploads.max_kb' => 64]);
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->create('big.pdf', 256, 'application/pdf')],
        ['Accept' => 'application/json'],
    )->assertStatus(422)->assertJsonValidationErrors('file');
});

it('attaches to a PC unit and binds exactly one target', function () {
    $pcUnit = PcUnit::factory()->create();

    $this->actingAs($this->admin)->post(
        "/api/admin/pc-units/{$pcUnit->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('rear.jpg')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = AssetAttachment::query()->firstOrFail();

    expect($attachment->pc_unit_id)->toBe($pcUnit->id)
        ->and($attachment->asset_id)->toBeNull();
});

it('streams a download and removes an attachment with its file', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('manual.png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = AssetAttachment::query()->firstOrFail();
    $path = $attachment->storage_path;

    $this->actingAs($this->admin)
        ->get("/api/admin/asset-attachments/{$attachment->uuid}")
        ->assertOk();

    $this->actingAs($this->admin)
        ->deleteJson("/api/admin/asset-attachments/{$attachment->uuid}")
        ->assertOk();

    expect(AssetAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);

    // The audit row outlives the file, so the timeline still records the removal.
    expect(ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::AssetAttachmentRemoved->value)
        ->exists())->toBeTrue();
});

it('closes attachment download and upload to non-administrators', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('private.png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $attachment = AssetAttachment::query()->firstOrFail();

    foreach (['technician', 'teacher'] as $roleSlug) {
        $actor = userWithRole($roleSlug);

        $this->actingAs($actor)
            ->getJson("/api/admin/asset-attachments/{$attachment->uuid}")
            ->assertForbidden();

        $this->actingAs($actor)->post(
            "/api/admin/assets/{$asset->uuid}/attachments",
            ['file' => UploadedFile::fake()->image('nope.png')],
            ['Accept' => 'application/json'],
        )->assertForbidden();

        $this->actingAs($actor)
            ->deleteJson("/api/admin/asset-attachments/{$attachment->uuid}")
            ->assertForbidden();
    }
});

it('audits an upload', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->post(
        "/api/admin/assets/{$asset->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('audited.png')],
        ['Accept' => 'application/json'],
    )->assertCreated();

    expect(ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::AssetAttachmentAdded->value)
        ->exists())->toBeTrue();
});
