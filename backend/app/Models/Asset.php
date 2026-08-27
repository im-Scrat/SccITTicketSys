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
        // catalog entry drops out of the default scope. The asset tag is the
        // last-resort name — every asset has one, and it is unique.
        $model = $this->getRelationValue('hardwareModel');

        return $model instanceof HardwareModel ? $model->model_name : $this->asset_tag;
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
