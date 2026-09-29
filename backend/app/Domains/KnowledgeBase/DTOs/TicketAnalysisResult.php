<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Enums\AiSeverity;

/**
 * The validated, typed shape of {@see TicketPreScreeningAgent}'s
 * structured output — WP-I's own "malformed output" check.
 *
 * {@see SafeAgentInvoker} already refuses
 * an empty structured payload (the SDK's own silent-`[]`-on-unparseable-JSON
 * case), but a non-empty array can still be the wrong shape: a missing key, a
 * severity string the model invented rather than one of the four this
 * application recognizes, a confidence outside 0–1, a `recommendations` entry
 * that isn't a string. None of that should ever reach an `ai_analysis_logs`
 * row or an `ai_recommendations` insert — this class is the one place that
 * shape is enforced, so {@see AnalyzeTicketJob}
 * only ever writes a row it already knows is well-formed.
 */
final readonly class TicketAnalysisResult
{
    /**
     * @param  list<string>  $recommendations
     */
    private function __construct(
        public string $problemCategory,
        public AiSeverity $severity,
        public int $estimatedResolutionMinutes,
        public bool $technicianRequired,
        public float $confidence,
        public string $summary,
        public array $recommendations,
    ) {}

    /**
     * @param  array<string, mixed>  $structured
     *
     * @throws AiProviderException when the shape does not hold
     */
    public static function fromStructured(array $structured): self
    {
        $category = $structured['problem_category'] ?? null;
        $severityValue = $structured['severity'] ?? null;
        $minutes = $structured['estimated_resolution_minutes'] ?? null;
        $technicianRequired = $structured['technician_required'] ?? null;
        $confidence = $structured['confidence'] ?? null;
        $summary = $structured['summary'] ?? null;
        $recommendations = $structured['recommendations'] ?? null;

        if (
            ! is_string($category) || trim($category) === ''
            || ! is_string($severityValue) || AiSeverity::tryFrom($severityValue) === null
            || ! is_int($minutes) || $minutes < 0
            || ! is_bool($technicianRequired)
            || ! is_numeric($confidence) || (float) $confidence < 0.0 || (float) $confidence > 1.0
            || ! is_string($summary) || trim($summary) === ''
            || ! is_array($recommendations) || $recommendations === []
            || ! self::isListOfNonEmptyStrings($recommendations)
        ) {
            throw AiProviderException::malformedOutput();
        }

        return new self(
            problemCategory: $category,
            severity: AiSeverity::from($severityValue),
            estimatedResolutionMinutes: $minutes,
            technicianRequired: $technicianRequired,
            confidence: (float) $confidence,
            summary: $summary,
            recommendations: array_values($recommendations),
        );
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function isListOfNonEmptyStrings(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return false;
            }
        }

        return true;
    }
}
