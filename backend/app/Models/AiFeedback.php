<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    /** @use HasFactory<AiFeedbackFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'was_helpful' => 'boolean',
        ];
    }

    /** @return BelongsTo<AiRecommendation, $this> */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(AiRecommendation::class, 'ai_recommendation_id');
    }

    /** @return BelongsTo<AiAnalysisLog, $this> */
    public function analysisLog(): BelongsTo
    {
        return $this->belongsTo(AiAnalysisLog::class, 'ai_analysis_log_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
