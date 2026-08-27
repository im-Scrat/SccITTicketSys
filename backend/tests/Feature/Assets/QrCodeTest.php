<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\QrStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\QrScanLog;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

it('generates a QR code bound to exactly one target', function () {
    $asset = Asset::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/admin/assets/{$asset->uuid}/qr")
        ->assertCreated()
        ->assertJsonPath('data.target_type', 'asset')
        ->assertJsonPath('data.status', 'active');

    $code = QrCode::query()->where('asset_id', $asset->id)->firstOrFail();

    expect($code->pc_unit_id)->toBeNull()
        ->and($code->code)->toStartWith('AS-')
        ->and($code->generated_at)->not->toBeNull()
        // The payload is a deep link, so a phone camera resolves it with no app.
        ->and($code->payload)->toContain('/qr/'.$code->code);

    // The rendered label travels with the response as inline SVG.
    expect($response->json('meta.svg'))->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(substr($response->json('meta.svg'), strlen('data:image/svg+xml;base64,')));
    expect($svg)->toContain('<svg');
});

it('renders a PC label and keeps qr_identifier in step', function () {
    $pcUnit = PcUnit::factory()->create(['qr_identifier' => null]);

    $this->actingAs($this->admin)->postJson("/api/admin/pc-units/{$pcUnit->uuid}/qr")
        ->assertCreated()
        ->assertJsonPath('data.target_type', 'pc_unit');

    $code = QrCode::query()->where('pc_unit_id', $pcUnit->id)->firstOrFail();

    // `pc_units.qr_identifier` is a denormalized convenience copy (FR-QR-002),
    // written only by the service so it cannot drift.
    expect($pcUnit->fresh()->qr_identifier)->toBe($code->code)
        ->and($code->asset_id)->toBeNull();
});

it('does not issue a second active label for the same target', function () {
    $asset = Asset::factory()->create();

    $first = $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated();
    $second = $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated();

    expect($second->json('data.code'))->toBe($first->json('data.code'))
        ->and(QrCode::query()->where('asset_id', $asset->id)->count())->toBe(1);
});

it('preserves scan history when a code is regenerated', function () {
    $asset = Asset::factory()->create();

    $original = $this->actingAs($this->admin)
        ->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated()->json('data.code');

    $originalRow = QrCode::query()->where('code', $original)->firstOrFail();

    // A scan recorded against the original label.
    QrScanLog::query()->create([
        'qr_code_id' => $originalRow->id,
        'asset_id' => $asset->id,
        'scanned_by' => $this->admin->id,
        'scan_result' => 'success',
        'scanned_at' => now(),
    ]);

    $replacement = $this->actingAs($this->admin)
        ->postJson("/api/admin/assets/{$asset->uuid}/qr/regenerate")->assertCreated()->json('data.code');

    expect($replacement)->not->toBe($original);

    // The old code is revoked, not deleted, so its scan log still resolves
    // (FR-QR-007) — "what was scanned in March" keeps an answer.
    $originalRow->refresh();
    expect($originalRow->status)->toBe(QrStatus::Revoked)
        ->and(QrScanLog::query()->where('qr_code_id', $originalRow->id)->count())->toBe(1);

    expect(QrCode::query()->where('code', $replacement)->first()->status)->toBe(QrStatus::Active);
});

it('revokes every active label without issuing a replacement', function () {
    $pcUnit = PcUnit::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/admin/pc-units/{$pcUnit->uuid}/qr")->assertCreated();

    $this->actingAs($this->admin)->postJson("/api/admin/pc-units/{$pcUnit->uuid}/qr/revoke")
        ->assertOk()
        ->assertJsonPath('revoked', 1);

    expect(QrCode::query()->where('pc_unit_id', $pcUnit->id)->where('status', 'active')->count())->toBe(0)
        // The convenience copy is cleared too, so nothing claims a live label.
        ->and($pcUnit->fresh()->qr_identifier)->toBeNull();
});

it('lists the label history including revoked codes', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated();
    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr/regenerate")->assertCreated();

    $response = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/qr")->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('status')->sort()->values()->toArray())
        ->toBe(['active', 'revoked'])
        ->and($response->json('meta.active.status'))->toBe('active')
        ->and($response->json('meta.default_size'))->toBe(256)
        ->and($response->json('meta.error_correction'))->toBe('M');
});

it('serves the print view and audits the print', function () {
    $asset = Asset::factory()->create(['asset_tag' => 'PRINT-1']);

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated();

    $response = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/qr/print?size=512")
        ->assertOk();

    expect($response->json('meta.svg'))->toStartWith('data:image/svg+xml;base64,')
        ->and($response->json('meta.identifier'))->toBe('PRINT-1');

    // A label leaving the building on a sticker is a real, traceable event.
    expect(ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::QrPrinted->value)
        ->exists())->toBeTrue();
});

it('refuses to print when there is no active label', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->getJson("/api/admin/assets/{$asset->uuid}/qr/print")
        ->assertStatus(422);
});

it('audits generation, regeneration and revocation distinctly', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated();
    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr/regenerate")->assertCreated();
    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr/revoke")->assertOk();

    foreach ([ActivityAction::QrGenerated, ActivityAction::QrRegenerated, ActivityAction::QrRevoked] as $action) {
        expect(ActivityLog::query()
            ->where('subject_id', $asset->id)
            ->where('action', $action->value)
            ->exists())->toBeTrue();
    }
});
