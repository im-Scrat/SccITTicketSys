<?php

declare(strict_types=1);

use App\Domains\Assets\Services\QrService;
use App\Models\PcUnit;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/*
 * Public QR labels (owner brief 2026-10-20, SDD DD-117; builds on DD-113).
 *
 * A PC label must open the public site from any phone camera, so it encodes
 * `<FRONTEND_URL>/qr/<code>` — on the Render deployment that is
 * https://sccit-itsm.onrender.com. The label image is rendered from the
 * *current* FRONTEND_URL (DD-113), so a label re-printed after a move carries
 * the new address while the code, and its scan history, stay the same.
 */

const RENDER_ORIGIN = 'https://sccit-itsm.onrender.com';

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
    config(['app.frontend_url' => RENDER_ORIGIN, 'app.url' => RENDER_ORIGIN]);
});

/** The SVG the service would print for exactly this text. */
function svgFor(string $text, QrService $service): string
{
    $writer = new Writer(new ImageRenderer(new RendererStyle($service->defaultSize(), 1), new SvgImageBackEnd));

    $level = match (strtoupper($service->defaultErrorCorrection())) {
        'L' => ErrorCorrectionLevel::L(),
        'Q' => ErrorCorrectionLevel::Q(),
        'H' => ErrorCorrectionLevel::H(),
        default => ErrorCorrectionLevel::M(),
    };

    return $writer->writeString($text, 'UTF-8', $level);
}

it('issues a PC label whose URL is the public origin’s /qr/<code>', function () {
    $pc = PcUnit::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/admin/pc-units/{$pc->uuid}/qr")->assertCreated();

    $code = $pc->fresh()->qrCodes()->latest('id')->firstOrFail();

    expect($code->code)->toMatch('/^PC-[A-Z0-9]{10}$/')
        ->and($code->payload)->toBe(RENDER_ORIGIN.'/qr/'.$code->code)
        ->and($code->payload)->not->toContain('localhost');
});

it('prints the label from the current public origin, even for a code issued under another', function () {
    $pc = PcUnit::factory()->create();
    $service = app(QrService::class);

    // Issued while the system still answered on the local address …
    config(['app.frontend_url' => 'http://localhost:8081']);
    $code = $service->generate($pc);
    expect($code->payload)->toBe('http://localhost:8081/qr/'.$code->code);

    // … and printed after the move: same code, public address.
    config(['app.frontend_url' => RENDER_ORIGIN]);

    expect($service->render($code))->toBe(svgFor(RENDER_ORIGIN.'/qr/'.$code->code, $service))
        ->and($service->render($code))->not->toBe(svgFor($code->payload, $service));
});

it('lands a scanned public URL on the public landing route the SPA serves', function () {
    $pc = PcUnit::factory()->create();
    $code = app(QrService::class)->generate($pc);

    // The path a phone opens is /qr/<code>; its scan endpoint answers an
    // anonymous visitor with "sign in" — never with anything about the PC.
    expect(parse_url((string) $code->payload, PHP_URL_PATH))->toBe('/qr/'.$code->code);

    $this->postJson("/api/qr/{$code->code}/scan")
        ->assertOk()
        ->assertExactJson(['next' => 'sign-in']);
});
