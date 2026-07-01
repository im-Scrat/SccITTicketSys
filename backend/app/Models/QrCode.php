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
