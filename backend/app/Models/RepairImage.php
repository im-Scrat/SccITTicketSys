<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepairImageType;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\RepairImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
