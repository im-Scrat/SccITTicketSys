<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
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
 * @property PcStatus|null $pc_status_before
 * @property PcCondition|null $pc_condition_before
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * Relations, all typed nullable.
 *
 * `ticket_id`, `pc_unit_id`, `asset_id`, `created_by` and `updated_by` are
 * nullable columns, so those relations resolve to null in ordinary use — an
 * asset-only record has no PC, a preventive visit has no ticket.
 *
 * `technician_id` and `maintenance_type_id` are NOT NULL, but the relations are
 * still declared nullable, and deliberately: a `belongsTo` resolves through a
 * *query*, so it yields null whenever the parent is not actually reachable —
 * a technician archived by the soft-delete scope, a row read in a partial
 * `select` that omitted the key. Declaring them non-null would make every
 * existing `?->` in the Assets and Analytics domains look like dead defence and
 * invite someone to remove it, which is exactly how a null-pointer bug gets
 * introduced on a Monday morning.
 * @property-read Ticket|null $ticket
 * @property-read PcUnit|null $pcUnit
 * @property-read Asset|null $asset
 * @property-read User|null $technician
 * @property-read MaintenanceType|null $type
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
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
            // The PC state this visit displaced (FR-MNT-008). Cast to the
            // enums so the restore is type-safe; nullable throughout,
            // because an asset-only record never touches a PC.
            'pc_status_before' => PcStatus::class,
            'pc_condition_before' => PcCondition::class,
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

    /**
     * Who opened the record.
     *
     * Distinct from {@see technician()} on purpose: FR-MNT-011 scopes a
     * technician to the records they were *assigned* **or** *created*, and an
     * administrator scheduling work for someone else makes those two different
     * people. Neither column is ever taken from request input.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Still open — neither completed nor cancelled. */
    public function isOpen(): bool
    {
        return ! in_array($this->status, [MaintenanceStatus::Completed, MaintenanceStatus::Cancelled], true);
    }
}
