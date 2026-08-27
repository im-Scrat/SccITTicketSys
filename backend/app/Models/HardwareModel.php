<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HardwareModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A specific SKU under a {@see HardwareComponent} (SRS FR-AST-001).
 *
 * @property int $id
 * @property int $hardware_component_id
 * @property string $model_name
 * @property string|null $model_number
 * @property array<string, mixed>|null $specifications
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class HardwareModel extends Model
{
    /** @use HasFactory<HardwareModelFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'specifications' => 'array',
        ];
    }

    /** @return BelongsTo<HardwareComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(HardwareComponent::class, 'hardware_component_id');
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
