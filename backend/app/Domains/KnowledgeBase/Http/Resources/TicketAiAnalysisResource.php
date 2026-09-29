<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Resources;

use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\Tickets\Http\Controllers\TicketAiAnalysisController;
use App\Models\AiAnalysisLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Teacher AI panel's payload (WP-I) — the full pre-screening for one
 * ticket, wherever {@see TicketAiAnalysisController}
 * has already established the caller holds `TicketVisibility::LEVEL_FULL`.
 *
 * **Confidence-aware, never presented as fact** (the WP-I mandate, verbatim):
 * `ai_generated` and `advisory` are constant markers on every row this
 * resource ever renders, and `meets_confidence_threshold` tells the caller,
 * unambiguously, whether the configured `ai_system_settings.confidence_
 * threshold` was met — the data is still returned below threshold (an
 * assigned technician's own judgment about a low-confidence read is still
 * useful triage context), but never without that flag sitting beside it.
 *
 * @mixin AiAnalysisLog
 */
class TicketAiAnalysisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $threshold = app(AiSettings::class)->confidenceThreshold();
        $confidence = $this->confidence_score !== null ? (float) $this->confidence_score : null;

        return [
            // Constant on every row: this endpoint has no other kind of content.
            'ai_generated' => true,
            'advisory' => true,

            'confidence' => $confidence,
            'confidence_threshold' => $threshold,
            'meets_confidence_threshold' => $threshold === null || $confidence === null
                ? null
                : $confidence >= $threshold,

            'problem_category' => $this->problem_category,
            'severity' => $this->severity?->value,
            'estimated_resolution_minutes' => $this->estimated_resolution_minutes,
            'technician_required' => (bool) $this->technician_required,
            'summary' => $this->summary,

            'recommendations' => $this->whenLoaded(
                'recommendations',
                fn () => $this->recommendations
                    ->sortBy('step_order')
                    ->values()
                    ->map(fn ($recommendation): array => [
                        'step' => $recommendation->step_order,
                        'text' => $recommendation->recommendation,
                        'is_completed' => (bool) $recommendation->is_completed,
                    ]),
            ),

            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
        ];
    }
}
