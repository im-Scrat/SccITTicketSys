<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanResult;
use Database\Factories\QrScanLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrScanLog extends Model
{
    /** @use HasFactory<QrScanLogFactory> */
    use HasFactory;

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
