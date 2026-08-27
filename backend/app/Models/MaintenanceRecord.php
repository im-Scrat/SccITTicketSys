<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\MaintenanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $ticket_id
 * @property int|null $pc_unit_id
 * @property int|null $asset_id
 * @property int $technician_id
 * @property int $maintenance_type_id
 * @property string $title
 * @property string|null $diagnosis
 * @property string|null $root_cause
 * @property string|null $resolution
 * @property string|null $preventive_recommendation
 * @property int|null $downtime_minutes
 * @property string|null $labor_hours
 * @property string|null $cost
 * @property MaintenanceStatus $status
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $maintenance_date
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class MaintenanceRecord extends Model
{
    /** @use HasFactory<MaintenanceRecordFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MaintenanceStatus::class,
            'labor_hours' => 'decimal:2',
            'cost' => 'decimal:2',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'maintenance_date' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
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

    /** @return BelongsTo<User, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /** @return BelongsTo<MaintenanceType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(MaintenanceType::class, 'maintenance_type_id');
    }

    /** @return HasMany<MaintenanceChecklist, $this> */
    public function checklists(): HasMany
    {
        return $this->hasMany(MaintenanceChecklist::class);
    }

    /** @return HasMany<RepairImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(RepairImage::class);
    }

    /** @return HasMany<MaintenanceNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(MaintenanceNote::class);
    }

    /** @return HasMany<HardwareReplacement, $this> */
    public function hardwareReplacements(): HasMany
    {
        return $this->hasMany(HardwareReplacement::class);
    }
}
