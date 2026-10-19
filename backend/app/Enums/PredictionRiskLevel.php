<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How serious a predictive-maintenance finding is judged to be (WP-L).
 *
 * A category, deliberately — not a percentage. Nothing in this system is a
 * calibrated failure model, so a number such as "73%" would be a fabricated
 * probability wearing the costume of a measurement. `ai_predictions.probability`
 * stays null for AI-generated predictions; this is what carries the judgement.
 */
enum PredictionRiskLevel: string
{
    use HasValues;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Badge tone for the review surface, returned by the API so the client never
     * picks the hue (the `AssetStatus::tone()` convention). High is amber, not
     * red: red is reserved for failed/offline equipment (DESIGN.md), and a risk
     * is a warning about the future, not a fault that exists.
     *
     * @return 'neutral'|'info'|'warning'
     */
    public function tone(): string
    {
        return match ($this) {
            self::Low => 'neutral',
            self::Medium => 'info',
            self::High => 'warning',
        };
    }
}
