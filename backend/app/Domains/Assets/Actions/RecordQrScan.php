<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\DTOs\QrScanOutcome;
use App\Models\PcUnit;
use App\Models\QrScanLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Write the scan to `qr_scan_logs` (SRS FR-QR-005/013; SDD DD-47).
 *
 * **Every attempt, authenticated or not.** FR-QR-005 says "record **every** scan
 * attempt ... with result, scanner (null when unauthenticated), IP, and optional
 * geolocation", and AC-QR-010 tests that an anonymous scan lands with a null
 * scanner. This runs *before* any authorization decision and independently of
 * it — which is what makes an enumeration sweep visible after the fact (DD-47).
 * A log written only for permitted scans would record everything except the
 * attempts worth investigating.
 *
 * **No `activity_logs` row.** A scan is an attempt, not an actor-initiated
 * business event, and it happens with no actor at all more often than not. The
 * split mirrors `login_history` versus `activity_logs`, which the Identity
 * domain has drawn since Phase 2.2: attempts in their own table, business
 * events in the shared audit trail. Proof of work — which *is* a business
 * event — writes to `activity_logs` when it arrives in Stage E.
 */
class RecordQrScan
{
    /**
     * @param  array{latitude?: float|null, longitude?: float|null}  $geo
     */
    public function handle(
        QrScanOutcome $outcome,
        ?User $scanner,
        Request $request,
        array $geo = [],
    ): QrScanLog {
        return DB::transaction(function () use ($outcome, $scanner, $request, $geo): QrScanLog {
            $target = $outcome->target;

            $log = QrScanLog::query()->create([
                'qr_code_id' => $outcome->qrCode?->getKey(),
                // Denormalized alongside `qr_code_id` because that column is
                // `ON DELETE SET NULL`: when a code row is eventually purged the
                // log must still be able to say which machine was scanned.
                'pc_unit_id' => $target instanceof PcUnit ? $target->getKey() : null,
                'asset_id' => $target !== null && ! $target instanceof PcUnit ? $target->getKey() : null,
                // Bound later, by the proof-of-work submission (FR-MNT-012,
                // DD-50). Null here is the honest state: nothing has been
                // submitted against this scan yet.
                'maintenance_record_id' => null,
                'scanned_by' => $scanner?->getKey(),
                'scan_result' => $outcome->result->value,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'latitude' => $geo['latitude'] ?? null,
                'longitude' => $geo['longitude'] ?? null,
                'scanned_at' => now(),
                'created_at' => now(),
            ]);

            /*
             * `last_scanned_at` is stamped whenever the **code row resolved** —
             * including when it is revoked or its machine is retired.
             *
             * FR-QR-004 asks the field to track when the label was last scanned,
             * and a revoked sticker that is still being scanned is a fact worth
             * having: it is how an administrator learns an old label is still on
             * a machine and needs replacing. Stamping only successes would make
             * exactly that situation invisible.
             */
            $outcome->qrCode?->forceFill(['last_scanned_at' => now()])->save();

            return $log;
        });
    }
}
