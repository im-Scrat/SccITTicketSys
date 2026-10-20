<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\PcCondition;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A serialized asset — one row is one physical unit (SRS FR-AST-002).
 *
 * @property int $id
 * @property string $uuid
 * @property string $asset_tag
 * @property string|null $name
 * @property int $hardware_model_id
 * @property int|null $supplier_id
 * @property int|null $current_room_id
 * @property int|null $assigned_technician_id
 * @property string|null $serial_number
 * @property string|null $barcode
 * @property AssetStatus $status
 * @property PcCondition $condition
 * @property string|null $purchase_price
 * @property Carbon|null $purchase_date
 * @property Carbon|null $warranty_expiration
 * @property string|null $notes
 * @property string|null $category_detail
 * @property Carbon|null $placement_confirmed_at
 * @property int|null $placement_confirmed_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'condition' => PcCondition::class,
            'purchase_price' => 'decimal:2',
            'purchase_date' => 'date',
            'warranty_expiration' => 'date',
            'placement_confirmed_at' => 'datetime',
        ];
    }

    /**
     * What to call this asset. Phase 2.5 added a per-unit `name` so two identical
     * printers can be told apart; where it is blank the catalog model name is
     * still the answer, which is why the column is nullable and no row needed
     * backfilling.
     */
    public function displayName(): string
    {
        $name = is_string($this->name) ? trim($this->name) : '';

        if ($name !== '') {
            return $name;
        }

        $this->loadMissing('hardwareModel');

        // The relation is typed non-null (the FK is NOT NULL), but it really can
        // resolve to null: `hardware_models` is soft-deletable, so a retired
        // catalog entry drops out of the default scope. "Unspecified model" is
        // a filing placeholder, not a name. The asset tag used to be the last
        // resort; it is no longer shown anywhere (owner request 2026-10-19).
        $model = $this->getRelationValue('hardwareModel');

        return $model instanceof HardwareModel && $model->model_name !== HardwareModel::UNSPECIFIED
            ? $model->model_name
            : 'Unnamed asset';
    }

    /**
     * The SQL twin of {@see displayName()} for report queries that read assets
     * through the query builder: the name, else the (live, specified) catalog
     * model's name, else "Unnamed asset". `$alias` is the assets table alias;
     * it is only ever a literal from the calling code, never input.
     */
    public static function displayNameSql(string $alias): string
    {
        $unspecified = HardwareModel::UNSPECIFIED;

        return "COALESCE(NULLIF(btrim({$alias}.name), ''), "
            ."(SELECT NULLIF(hm_dn.model_name, '{$unspecified}') FROM hardware_models hm_dn "
            ."WHERE hm_dn.id = {$alias}.hardware_model_id AND hm_dn.deleted_at IS NULL), "
            ."'Unnamed asset')";
    }

    /** True when a warranty exists and has not yet lapsed. */
    public function underWarranty(): bool
    {
        return $this->warranty_expiration !== null && ! $this->warranty_expiration->isPast();
    }

    /** Whole days until the warranty lapses; null when there is no warranty. */
    public function warrantyDaysRemaining(): ?int
    {
        if ($this->warranty_expiration === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->warranty_expiration->startOfDay(), false);
    }

    /** @return BelongsTo<HardwareModel, $this> */
    public function hardwareModel(): BelongsTo
    {
        return $this->belongsTo(HardwareModel::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function currentRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'current_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    /**
     * Who confirmed this asset is in its room (DD-115).
     *
     * @return BelongsTo<User, $this>
     */
    public function placementConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placement_confirmed_by');
    }

    /**
     * Where this asset stands on Floorplan layouts (DD-115). Only trusted while
     * the asset is still in that layout's room — FloorPlanService filters.
     *
     * @return HasMany<FloorPlanAssetPosition, $this>
     */
    public function floorPlanPositions(): HasMany
    {
        return $this->hasMany(FloorPlanAssetPosition::class);
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

    /** @return HasMany<AssetStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(AssetStatusHistory::class);
    }

    /** @return HasMany<PcComponentInstallation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(PcComponentInstallation::class);
    }

    /** @return HasMany<AssetTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class);
    }

    /** @return HasMany<AssetAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(AssetAttachment::class);
    }

    /**
     * Faults reported against this asset itself (SDD DD-79). Faults reported
     * against the PC it is fitted in are that PC's tickets, not these.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<QrCode, $this> */
    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return HasOne<DisposalRecord, $this> */
    public function disposalRecord(): HasOne
    {
        return $this->hasOne(DisposalRecord::class);
    }
}
