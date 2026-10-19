<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\KnowledgeBase\Services\PcRiskAssessor;
use App\Models\AiPrediction;

/**
 * What one run of {@see PcRiskAssessor} concluded for one PC (WP-L).
 *
 * Four outcomes, and only one of them is a prediction:
 *
 * - `predicted` — a pattern was found and the model's reading of it passed
 *   validation; `$prediction` is the stored row.
 * - `insufficient` — no recurring pattern, or the model declined. `$message` is
 *   exactly {@see INSUFFICIENT_EVIDENCE}, the sentence the mandate requires.
 * - `unavailable` — a pattern was found but no prediction could be obtained
 *   (AI not configured, provider failure, malformed or overclaiming output).
 *   Deliberately *not* reported as "insufficient evidence": the evidence may be
 *   perfectly sufficient; the model simply did not answer usably.
 * - `disabled` — `ai_system_settings.enable_predictions` is off.
 */
final readonly class PcRiskAssessment
{
    public const INSUFFICIENT_EVIDENCE = 'Insufficient historical evidence for a reliable prediction.';

    public const PREDICTED = 'predicted';

    public const INSUFFICIENT = 'insufficient';

    public const UNAVAILABLE = 'unavailable';

    public const DISABLED = 'disabled';

    /**
     * @param  self::PREDICTED|self::INSUFFICIENT|self::UNAVAILABLE|self::DISABLED  $outcome
     * @param  list<DetectedPattern>  $patterns
     */
    private function __construct(
        public string $outcome,
        public ?string $message,
        public ?AiPrediction $prediction,
        public array $patterns,
    ) {}

    /**
     * @param  list<DetectedPattern>  $patterns
     */
    public static function predicted(AiPrediction $prediction, array $patterns): self
    {
        return new self(self::PREDICTED, null, $prediction, $patterns);
    }

    /**
     * @param  list<DetectedPattern>  $patterns
     */
    public static function insufficient(array $patterns = []): self
    {
        return new self(self::INSUFFICIENT, self::INSUFFICIENT_EVIDENCE, null, $patterns);
    }

    /**
     * @param  list<DetectedPattern>  $patterns
     */
    public static function unavailable(array $patterns): self
    {
        return new self(self::UNAVAILABLE, null, null, $patterns);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, null, null, []);
    }
}
