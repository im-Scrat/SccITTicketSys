<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\ManageQrCode;
use App\Domains\Assets\Http\Resources\QrCodeResource;
use App\Domains\Assets\Services\QrService;
use App\Enums\QrStatus;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * QR label management for assets and PC units (SRS FR-QR-001..004/007).
 *
 * Both target kinds share these endpoints. Laravel binds route parameters by
 * name, so `/assets/{asset}/qr` fills `$asset` and `/pc-units/{pc_unit}/qr`
 * fills `$pcUnit`; {@see target()} picks whichever arrived. That keeps the QR
 * contract defined once instead of duplicated per target kind.
 *
 * Scan verification (FR-QR-005/006/008) is deliberately **not** here — it
 * belongs to the technician verification workflow in a later phase, and it is a
 * differently-authorized endpoint reached from a phone camera rather than from
 * this administrator surface.
 *
 * Rendered SVG travels in `meta.svg` as a data URI, so the print view is one
 * request with nothing further to fetch; being vector text it costs a few KB
 * rather than an image download.
 */
class QrCodeController extends Controller
{
    public function __construct(private readonly QrService $qr) {}

    /** The label history for a target: the active code plus every revoked one. */
    public function index(Request $request, ?Asset $asset = null, ?PcUnit $pcUnit = null): JsonResponse
    {
        $target = $this->target($asset, $pcUnit);
        $this->authorize('manageQr', $target);

        $codes = $target->qrCodes()->orderByDesc('generated_at')->get();
        $active = $codes->first(fn (QrCode $code): bool => $code->status === QrStatus::Active);

        return QrCodeResource::collection($codes)
            ->additional(['meta' => [
                'active' => $active !== null ? new QrCodeResource($active) : null,
                'svg' => $active !== null ? $this->qr->renderDataUri($active) : null,
                'default_size' => $this->qr->defaultSize(),
                'error_correction' => $this->qr->defaultErrorCorrection(),
            ]])
            ->response();
    }

    /** Issue a label for a target that has none (FR-QR-001). */
    public function store(Request $request, ManageQrCode $action, ?Asset $asset = null, ?PcUnit $pcUnit = null): JsonResponse
    {
        $target = $this->target($asset, $pcUnit);
        $this->authorize('manageQr', $target);

        /** @var User $actor */
        $actor = $request->user();

        $qrCode = $action->generate($target, $actor, $request);

        return (new QrCodeResource($qrCode))
            ->additional([
                'message' => 'QR code generated.',
                'meta' => ['svg' => $this->qr->renderDataUri($qrCode)],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /** Replace the active label; the previous one is revoked, not deleted. */
    public function regenerate(Request $request, ManageQrCode $action, ?Asset $asset = null, ?PcUnit $pcUnit = null): JsonResponse
    {
        $target = $this->target($asset, $pcUnit);
        $this->authorize('manageQr', $target);

        /** @var User $actor */
        $actor = $request->user();

        $qrCode = $action->regenerate($target, $actor, $request);

        // 201, not 200: regeneration issues a *new* code resource (the previous
        // one is revoked, not edited). Stated explicitly rather than inherited
        // from the model's `wasRecentlyCreated` flag, so the contract is
        // deliberate and cannot shift if the Action changes shape.
        return (new QrCodeResource($qrCode))
            ->additional([
                'message' => 'QR code regenerated. The previous code has been revoked.',
                'meta' => ['svg' => $this->qr->renderDataUri($qrCode)],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /** Withdraw every active label without issuing a replacement. */
    public function revoke(Request $request, ManageQrCode $action, ?Asset $asset = null, ?PcUnit $pcUnit = null): JsonResponse
    {
        $target = $this->target($asset, $pcUnit);
        $this->authorize('manageQr', $target);

        /** @var User $actor */
        $actor = $request->user();

        $revoked = $action->revoke($target, $actor, $request);

        return response()->json([
            'message' => $revoked > 0 ? 'QR code revoked.' : 'There was no active QR code to revoke.',
            'revoked' => $revoked,
        ]);
    }

    /**
     * The print view's payload (FR-QR-003). An explicit size is allowed so a
     * sheet of labels can ask for the physical dimension it needs. The print is
     * audited — a label leaving the building on a sticker is a real event worth
     * being able to trace (FR-AUD-003).
     */
    public function print(Request $request, ManageQrCode $action, ?Asset $asset = null, ?PcUnit $pcUnit = null): JsonResponse
    {
        $target = $this->target($asset, $pcUnit);
        $this->authorize('manageQr', $target);

        /** @var User $actor */
        $actor = $request->user();

        $qrCode = $this->qr->activeCodeFor($target);

        if ($qrCode === null) {
            return response()->json([
                'message' => 'This record has no active QR code. Generate one before printing.',
            ], 422);
        }

        $size = $request->integer('size') ?: null;
        $action->recordPrint($target, $qrCode, $actor, $request);

        return (new QrCodeResource($qrCode))
            ->additional(['meta' => [
                'svg' => $this->qr->renderDataUri($qrCode, $size),
                'label' => $target instanceof PcUnit ? $target->pc_name : $target->displayName(),
                'identifier' => $target instanceof PcUnit ? $target->unit_code : $target->asset_tag,
                'location' => $qrCode->location_label,
            ]])
            ->response();
    }

    /**
     * Exactly one of the two route bindings is populated; which one depends on
     * the route the request came in on.
     */
    private function target(?Asset $asset, ?PcUnit $pcUnit): Asset|PcUnit
    {
        return $asset ?? $pcUnit ?? throw new RuntimeException('QR route bound no target.');
    }
}
