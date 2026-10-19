<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Enums\QrStatus;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\SystemSetting;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QR generation, regeneration and revocation (SRS FR-QR-001..004/007; SDD §24).
 *
 * **One target, always.** A code binds a PC unit *or* a standalone asset, never
 * both and never neither — enforced by the `qr_codes_target_check` database
 * constraint (`num_nonnulls(pc_unit_id, asset_id) = 1`), which this service
 * simply respects rather than reimplements (FR-QR-001, BR-09).
 *
 * **History survives regeneration.** Regenerating does not mutate the existing
 * row: the old code is marked `revoked` and a new row is inserted, so the scan
 * logs attached to the old code remain attached to it and the audit question
 * "what was scanned in March?" still has an answer (FR-QR-007).
 *
 * **Rendering is SVG.** `bacon/bacon-qr-code` writes vector output with no image
 * extension involved, so a printed label is crisp at any physical size and the
 * response is a few KB of text that the browser can style and print directly
 * (FR-QR-003). Size and error-correction come from the `qr.default_size` /
 * `qr.error_correction` rows already present in `system_settings`.
 *
 * Scan verification (FR-QR-005/006/008) is deliberately **not** implemented here
 * — it belongs to the technician verification workflow in a later phase.
 */
class QrService
{
    /** Fallbacks used when the settings rows are absent. */
    public const DEFAULT_SIZE = 256;

    public const DEFAULT_ERROR_CORRECTION = 'M';

    private const MIN_SIZE = 64;

    private const MAX_SIZE = 2048;

    /**
     * Issue a code for a target that has none. Idempotent by intent: if an active
     * code already exists it is returned rather than duplicated, so a double
     * click cannot leave two live labels for one machine.
     */
    public function generate(Asset|PcUnit $target): QrCode
    {
        $existing = $this->activeCodeFor($target);

        if ($existing !== null) {
            return $existing;
        }

        return $this->issue($target);
    }

    /**
     * Replace the active code with a fresh one — used when a printed label is
     * damaged, lost, or possibly compromised. The prior code is revoked, not
     * deleted, so its scan history stays intact (FR-QR-007).
     */
    public function regenerate(Asset|PcUnit $target): QrCode
    {
        return DB::transaction(function () use ($target): QrCode {
            $this->revokeAllFor($target);

            return $this->issue($target);
        });
    }

    /**
     * Withdraw every active code for a target without issuing a replacement —
     * for equipment leaving the estate.
     */
    public function revoke(Asset|PcUnit $target): int
    {
        return DB::transaction(fn (): int => $this->revokeAllFor($target));
    }

    /** The currently active code for a target, if any. */
    public function activeCodeFor(Asset|PcUnit $target): ?QrCode
    {
        return $this->codesFor($target)
            ->where('status', QrStatus::Active->value)
            ->latest('generated_at')
            ->first();
    }

    /**
     * Render a code as an inline SVG document.
     *
     * @param  int|null  $size  pixel size; clamped to a sane range
     * @param  string|null  $errorCorrection  L|M|Q|H
     */
    public function render(QrCode $qrCode, ?int $size = null, ?string $errorCorrection = null): string
    {
        $size = $this->resolveSize($size);

        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString(
            $qrCode->payload ?: $qrCode->code,
            'UTF-8',
            $this->errorCorrectionLevel($errorCorrection),
        );
    }

    /**
     * A data URI of the rendered SVG, for embedding in an `<img>` without a
     * second request.
     */
    public function renderDataUri(QrCode $qrCode, ?int $size = null, ?string $errorCorrection = null): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->render($qrCode, $size, $errorCorrection));
    }

    /** The configured default rendering size. */
    public function defaultSize(): int
    {
        return $this->resolveSize(null);
    }

    /** The configured default error-correction level. */
    public function defaultErrorCorrection(): string
    {
        return $this->settingString('qr.error_correction', self::DEFAULT_ERROR_CORRECTION);
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Insert a new active code bound to exactly one target, and keep
     * `pc_units.qr_identifier` in step with it (FR-QR-002 — the column is a
     * denormalized convenience copy, so it is written here and nowhere else).
     */
    private function issue(Asset|PcUnit $target): QrCode
    {
        $code = $this->uniqueCode($target);

        return DB::transaction(function () use ($target, $code): QrCode {
            $qr = QrCode::query()->create([
                'pc_unit_id' => $target instanceof PcUnit ? $target->getKey() : null,
                'asset_id' => $target instanceof Asset ? $target->getKey() : null,
                'code' => $code,
                'payload' => $this->payloadFor($code),
                'location_label' => $this->locationLabel($target),
                'status' => QrStatus::Active->value,
                'generated_at' => now(),
            ]);

            if ($target instanceof PcUnit) {
                $target->forceFill(['qr_identifier' => $code])->save();
            }

            return $qr;
        });
    }

    private function revokeAllFor(Asset|PcUnit $target): int
    {
        $revoked = $this->codesFor($target)
            ->where('status', QrStatus::Active->value)
            ->update(['status' => QrStatus::Revoked->value, 'updated_at' => now()]);

        if ($target instanceof PcUnit && $revoked > 0) {
            $target->forceFill(['qr_identifier' => null])->save();
        }

        return $revoked;
    }

    /**
     * @return Builder<QrCode>
     */
    private function codesFor(Asset|PcUnit $target)
    {
        return QrCode::query()->where(
            $target instanceof PcUnit ? 'pc_unit_id' : 'asset_id',
            $target->getKey(),
        );
    }

    /**
     * A short, human-transcribable, collision-checked code. Prefixed by target
     * kind so an operator reading a label aloud knows what they are holding.
     */
    private function uniqueCode(Asset|PcUnit $target): string
    {
        $prefix = $target instanceof PcUnit ? 'PC' : 'AS';

        do {
            $candidate = $prefix.'-'.strtoupper(Str::random(10));
        } while (QrCode::query()->where('code', $candidate)->exists());

        return $candidate;
    }

    /**
     * What a scanner actually receives. A deep link into the SPA means a phone
     * camera resolves it without a dedicated app (FR-QR-009), and the canonical
     * `code` stays recoverable from the URL for verification in a later phase.
     */
    private function payloadFor(string $code): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $base.'/qr/'.$code;
    }

    /** A printable "Building · Floor · Room" hint for the label (FR-QR-001). */
    private function locationLabel(Asset|PcUnit $target): ?string
    {
        $room = $target instanceof PcUnit ? $target->room : $target->currentRoom;

        if ($room === null) {
            return null;
        }

        $room->loadMissing('floor.building');
        $floor = $room->floor;

        $parts = array_values(array_filter([
            $floor?->building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $room->name,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function resolveSize(?int $size): int
    {
        $size ??= (int) $this->settingString('qr.default_size', (string) self::DEFAULT_SIZE);

        return max(self::MIN_SIZE, min($size, self::MAX_SIZE));
    }

    /**
     * Map the configured level to Bacon's enum. The setting is stored as a
     * letter (`"M"`) because that is what operators and the SRS speak
     * (FR-QR-003); the library wants its own type, and an unrecognized letter
     * falls back to the default rather than throwing — a bad settings row should
     * not stop a label printing.
     */
    private function errorCorrectionLevel(?string $level): ErrorCorrectionLevel
    {
        $level = strtoupper($level ?? $this->defaultErrorCorrection());

        return match ($level) {
            'L' => ErrorCorrectionLevel::L(),
            'Q' => ErrorCorrectionLevel::Q(),
            'H' => ErrorCorrectionLevel::H(),
            default => ErrorCorrectionLevel::M(),
        };
    }

    /**
     * Read a `system_settings` row, tolerating its absence. Values are stored as
     * text; JSON-encoded scalars are unwrapped.
     */
    private function settingString(string $key, string $fallback): string
    {
        $value = SystemSetting::query()->where('key', $key)->value('value');

        if ($value === null || $value === '') {
            return $fallback;
        }

        $decoded = json_decode((string) $value, true);

        if (is_scalar($decoded)) {
            return (string) $decoded;
        }

        return trim((string) $value, "\"'");
    }
}
