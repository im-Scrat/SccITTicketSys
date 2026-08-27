<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\PcUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $room_id
 * @property string $unit_code
 * @property string|null $asset_tag
 * @property string|null $hostname
 * @property string|null $qr_identifier
 * @property string $pc_name
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $ip_address
 * @property string|null $mac_address
 * @property Carbon|null $purchase_date
 * @property Carbon|null $warranty_expiration
 * @property PcStatus $status
 * @property PcCondition $current_condition
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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

    /** Whole days until the warranty lapses; null when there is no warranty. */
    public function warrantyDaysRemaining(): ?int
    {
        if ($this->warranty_expiration === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->warranty_expiration->startOfDay(), false);
    }

    /** The QR code currently bound to this unit, if one is active (FR-QR-004). */
    public function activeQrCode(): ?QrCode
    {
        return $this->qrCodes()
            ->where('status', QrStatus::Active->value)
            ->latest('generated_at')
            ->first();
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

    /** @return HasMany<AssetAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(AssetAttachment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
