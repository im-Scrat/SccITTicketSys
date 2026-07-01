<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\PcUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PcUnit extends Model
{
    /** @use HasFactory<PcUnitFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PcStatus::class,
            'current_condition' => PcCondition::class,
            'purchase_date' => 'date',
            'warranty_expiration' => 'date',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasOne<PcSpecification, $this> */
    public function specification(): HasOne
    {
        return $this->hasOne(PcSpecification::class);
    }

    /** @return HasMany<QrCode, $this> */
    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return HasMany<PcComponentInstallation, $this> */
    public function componentInstallations(): HasMany
    {
        return $this->hasMany(PcComponentInstallation::class);
    }

    /** @return HasMany<FloorPlanPosition, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(FloorPlanPosition::class);
    }
}
