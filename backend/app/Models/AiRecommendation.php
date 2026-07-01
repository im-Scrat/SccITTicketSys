<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiRecommendationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRecommendation extends Model
{
    /** @use HasFactory<AiRecommendationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_recommendations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiAnalysisLog, $this> */
    public function analysisLog(): BelongsTo
    {
        return $this->belongsTo(AiAnalysisLog::class, 'ai_analysis_log_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
