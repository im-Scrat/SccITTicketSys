<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiFailurePatternFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $pc_unit_id
 * @property int|null $hardware_component_id
 * @property string $pattern_name
 * @property string|null $detected_problem
 * @property int $occurrence_count
 * @property int|null $average_days_between_failures
 * @property string|null $confidence
 * @property Carbon|null $last_detected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiFailurePattern extends Model
{
    /** @use HasFactory<AiFailurePatternFactory> */
    use HasFactory;

    protected $table = 'ai_failure_patterns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'last_detected' => 'datetime',
        ];
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<HardwareComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(HardwareComponent::class, 'hardware_component_id');
    }
}
