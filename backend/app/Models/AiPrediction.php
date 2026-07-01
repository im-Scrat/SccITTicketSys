<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PredictionStatus;
use Database\Factories\AiPredictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPrediction extends Model
{
    /** @use HasFactory<AiPredictionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_predictions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PredictionStatus::class,
            'probability' => 'decimal:4',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
