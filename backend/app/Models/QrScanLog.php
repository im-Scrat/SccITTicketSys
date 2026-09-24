<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanResult;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\QrScanLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan attempt (SRS FR-QR-005/006).
 *
 * Written for **every** attempt, authenticated or not — `scanned_by` is null
 * when there was no session — so the table is an honest record of who tried
 * what, including the attempts that were refused (SDD DD-47).
 *
 * The public `uuid` (WP-2.6b) is what a proof-of-work submission quotes to make
 * the write idempotent on the scan rather than on the HTTP request
 * ([FR-MNT-012](#), DD-50). `maintenance_record_id` is the single nullable FK
 * that binding fills, so one scan can never end up describing two jobs.
 */
class QrScanLog extends Model
{
    /** @use HasFactory<QrScanLogFactory> */
    use HasFactory, HasUuidRouteKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scan_result' => ScanResult::class,
            'scanned_at' => 'datetime',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<QrCode, $this> */
    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
