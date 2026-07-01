<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiFailurePatternFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
