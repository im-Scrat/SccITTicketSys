<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PredictionRiskLevel;
use App\Enums\PredictionStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AiPredictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One predictive-maintenance finding for one PC (SRS FR-AI-011) — advisory.
 *
 * The five parts WP-L keeps apart live in separate columns: the observed facts
 * and the pattern in `evidence` (computed from `maintenance_records`, never by
 * the model), the predicted risk in `predicted_issue` / `risk_level`, the
 * action in `recommendation`, and the model's own `confidence`. `probability`
 * stays null for AI-generated rows — see {@see PredictionRiskLevel}.
 *
 * @property int $id
 * @property string $uuid
 * @property int $pc_unit_id
 * @property int|null $ai_model_id
 * @property int|null $ai_failure_pattern_id
 * @property string $predicted_issue
 * @property PredictionRiskLevel|null $risk_level
 * @property string|null $probability
 * @property string|null $confidence
 * @property int|null $predicted_within_days
 * @property string|null $explanation
 * @property string|null $recommendation
 * @property array<string, mixed>|null $evidence
 * @property PredictionStatus $status
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 */
class AiPrediction extends Model
{
    /** @use HasFactory<AiPredictionFactory> */
    use HasFactory, HasUuidRouteKey;

    public const UPDATED_AT = null;

    protected $table = 'ai_predictions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PredictionStatus::class,
            'risk_level' => PredictionRiskLevel::class,
            'probability' => 'decimal:4',
            'confidence' => 'decimal:4',
            'evidence' => 'array',
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

    /** @return BelongsTo<AiFailurePattern, $this> */
    public function failurePattern(): BelongsTo
    {
        return $this->belongsTo(AiFailurePattern::class, 'ai_failure_pattern_id');
    }
}
