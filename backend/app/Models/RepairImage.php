<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepairImageType;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\RepairImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $maintenance_record_id
 * @property int|null $uploaded_by
 * @property RepairImageType $image_type
 * @property string $disk
 * @property string $storage_path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string|null $checksum
 * @property string|null $caption
 * @property Carbon|null $created_at
 * @property-read User|null $uploadedBy
 */
class RepairImage extends Model
{
    /** @use HasFactory<RepairImageFactory> */
    use HasFactory, HasUuidRouteKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'image_type' => RepairImageType::class,
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
