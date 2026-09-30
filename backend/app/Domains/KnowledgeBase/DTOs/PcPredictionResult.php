<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\KnowledgeBase\Agents\PcFailurePredictionAgent;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Enums\PredictionRiskLevel;

/**
 * The validated shape of {@see PcFailurePredictionAgent}'s answer (WP-L).
 *
 * Beyond shape, one content rule is enforced here rather than trusted to the
 * instructions: **no overclaiming.** An answer that calls a failure certain,
 * guaranteed, inevitable or definite — or says a part "will fail" — is refused
 * as malformed, so no such sentence can reach an `ai_predictions` row however
 * the model was prompted. A refused answer means no prediction, not a
 * softened one: rewording the model's claim would be putting words in its
 * mouth.
 */
final readonly class PcPredictionResult
{
    private const ISSUE_MAX = 255;

    /**
     * Certainty the mandate forbids. Word-bounded and case-insensitive; "may
     * fail", "could fail" and "under certain loads" pass; "will fail",
     * "certain to", "certainly", "guaranteed" and "inevitable" do not.
     */
    private const OVERCLAIM = '/\b(guarantee[ds]?|inevitabl[ey]|definitely|definite\s+failure|certainly|certain\s+(to|that)|is\s+certain|will\s+(certainly\s+|definitely\s+)?fail)\b/i';

    private function __construct(
        public bool $sufficientEvidence,
        public ?string $predictedIssue,
        public ?PredictionRiskLevel $riskLevel,
        public ?float $confidence,
        public ?string $explanation,
        public ?string $recommendation,
    ) {}

    /**
     * @param  array<string, mixed>  $structured
     *
     * @throws AiProviderException when the shape or the content rule does not hold
     */
    public static function fromStructured(array $structured): self
    {
        $sufficient = $structured['sufficient_evidence'] ?? null;

        if (! is_bool($sufficient)) {
            throw AiProviderException::malformedOutput();
        }

        if ($sufficient === false) {
            return new self(false, null, null, null, null, null);
        }

        $issue = $structured['predicted_issue'] ?? null;
        $risk = $structured['risk_level'] ?? null;
        $confidence = $structured['confidence'] ?? null;
        $explanation = $structured['explanation'] ?? null;
        $recommendation = $structured['recommendation'] ?? null;

        if (
            ! self::nonEmpty($issue) || mb_strlen(trim($issue)) > self::ISSUE_MAX
            || ! is_string($risk) || PredictionRiskLevel::tryFrom($risk) === null
            || ! is_numeric($confidence) || (float) $confidence < 0.0 || (float) $confidence > 1.0
            || ! self::nonEmpty($explanation)
            || ! self::nonEmpty($recommendation)
        ) {
            throw AiProviderException::malformedOutput();
        }

        foreach ([$issue, $explanation, $recommendation] as $text) {
            if (preg_match(self::OVERCLAIM, $text) === 1) {
                throw AiProviderException::malformedOutput();
            }
        }

        return new self(
            sufficientEvidence: true,
            predictedIssue: trim($issue),
            riskLevel: PredictionRiskLevel::from($risk),
            confidence: (float) $confidence,
            explanation: trim($explanation),
            recommendation: trim($recommendation),
        );
    }

    /** @phpstan-assert-if-true string $value */
    private static function nonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
