<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Services\QrService;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Issue, replace, withdraw and print QR labels (SRS FR-QR-001..004/007).
 *
 * The three write operations share one Action because they are one decision from
 * the operator's point of view — "what label should this machine carry?" — and
 * because they must audit identically. {@see QrService} owns the mechanics; this
 * class owns the audit trail and the target-kind dispatch.
 *
 * Printing is audited too. A printed label leaves the building on a physical
 * sticker, so "who produced a label for this machine, and when?" is a question
 * the audit trail should be able to answer (FR-AUD-003).
 */
class ManageQrCode
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QrService $qr,
    ) {}

    public function generate(Asset|PcUnit $target, User $actor, Request $request): QrCode
    {
        $qrCode = $this->qr->generate($target);

        $this->record(ActivityAction::QrGenerated, $target, $actor, $request, [
            'code' => $qrCode->code,
            'location_label' => $qrCode->location_label,
        ], 'QR code '.$qrCode->code.' generated for '.$this->name($target));

        return $qrCode;
    }

    /**
     * Replace the active label. The previous code is revoked rather than deleted,
     * so its scan history remains attached to it (FR-QR-007).
     */
    public function regenerate(Asset|PcUnit $target, User $actor, Request $request): QrCode
    {
        $previous = $this->qr->activeCodeFor($target);
        $qrCode = $this->qr->regenerate($target);

        $this->record(ActivityAction::QrRegenerated, $target, $actor, $request, [
            'previous_code' => $previous?->code,
            'code' => $qrCode->code,
            'location_label' => $qrCode->location_label,
        ], 'QR code regenerated for '.$this->name($target));

        return $qrCode;
    }

    public function revoke(Asset|PcUnit $target, User $actor, Request $request): int
    {
        $previous = $this->qr->activeCodeFor($target);
        $revoked = $this->qr->revoke($target);

        $this->record(ActivityAction::QrRevoked, $target, $actor, $request, [
            'code' => $previous?->code,
            'revoked' => $revoked,
        ], 'QR code revoked for '.$this->name($target));

        return $revoked;
    }

    public function recordPrint(Asset|PcUnit $target, QrCode $qrCode, User $actor, Request $request): void
    {
        $this->record(ActivityAction::QrPrinted, $target, $actor, $request, [
            'code' => $qrCode->code,
        ], 'QR code '.$qrCode->code.' printed for '.$this->name($target));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(
        ActivityAction $action,
        Asset|PcUnit $target,
        User $actor,
        Request $request,
        array $properties,
        string $description,
    ): void {
        $this->audit->activity(
            $action,
            actor: $actor,
            subject: $target,
            properties: [
                ...$properties,
                'target_type' => $target instanceof PcUnit ? 'pc_unit' : 'asset',
            ],
            request: $request,
            module: 'assets',
            description: $description,
        );
    }

    private function name(Asset|PcUnit $target): string
    {
        return $target instanceof PcUnit ? $target->unit_code : $target->asset_tag;
    }
}
