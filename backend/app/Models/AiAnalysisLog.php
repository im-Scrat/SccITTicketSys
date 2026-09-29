<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiSeverity;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AiAnalysisLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $ticket_id
 * @property int|null $ai_model_id
 * @property Carbon|null $analyzed_at
 * @property string|null $confidence_score
 * @property string|null $problem_category
 * @property AiSeverity|null $severity
 * @property int|null $estimated_resolution_minutes
 * @property bool|null $technician_required
 * @property string|null $summary
 * @property array<string, mixed>|null $raw_response
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $latency_ms
 * @property Carbon|null $created_at
 */
class AiAnalysisLog extends Model
{
    /** @use HasFactory<AiAnalysisLogFactory> */
    use HasFactory, HasUuidRouteKey;

    public const UPDATED_AT = null;

    protected $table = 'ai_analysis_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'severity' => AiSeverity::class,
            'confidence_score' => 'decimal:4',
            'technician_required' => 'boolean',
            'raw_response' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /** @return HasMany<AiRecommendation, $this> */
    public function recommendations(): HasMany
    {
        return $this->hasMany(AiRecommendation::class, 'ai_analysis_log_id');
    }
}
