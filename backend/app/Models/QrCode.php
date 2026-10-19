<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QrStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\QrCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A QR label bound to exactly one target — a PC unit *or* a standalone asset
 * (SRS FR-QR-001; enforced by the `qr_codes_target_check` constraint).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $pc_unit_id
 * @property int|null $asset_id
 * @property string $code
 * @property string|null $payload
 * @property string|null $location_label
 * @property QrStatus $status
 * @property Carbon|null $generated_at
 * @property Carbon|null $last_scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class QrCode extends Model
{
    /** @use HasFactory<QrCodeFactory> */
    use HasFactory, HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => QrStatus::class,
            'generated_at' => 'datetime',
            'last_scanned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return HasMany<QrScanLog, $this> */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(QrScanLog::class);
    }
}
