<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComponentType;
use Database\Factories\HardwareComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A typed, generic catalog entry (SRS FR-AST-001) — "Office Laser Printer",
 * "16 GB DDR4 module" — one level above a specific model.
 *
 * @property int $id
 * @property ComponentType $component_type
 * @property int|null $manufacturer_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class HardwareComponent extends Model
{
    /** @use HasFactory<HardwareComponentFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
        ];
    }

    /** @return BelongsTo<Manufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /** @return HasMany<HardwareModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(HardwareModel::class);
    }
}
