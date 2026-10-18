<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\PcPredictionResult;
use App\Domains\KnowledgeBase\DTOs\ReportNarrativeResult;

/**
 * What AI-written advisory text may not say, in one place (Phase 2.10 R5,
 * SDD DD-95): certainty about the future, probabilities, links and contact
 * instructions, markup. Used by {@see PcPredictionResult} (WP-L, which this
 * absorbed its OVERCLAIM rule from) and {@see ReportNarrativeResult}.
 */
final class AdvisoryLanguage
{
    /** Presenting a failure or outcome as certain. */
    public const OVERCLAIM = '/\b(guarantee[ds]?|inevitabl[ey]|definitely|definite\s+failure|certainly|certain\s+(to|that)|is\s+certain|will\s+(certainly\s+|definitely\s+)?fail)\b/i';

    /** A probability the application has no calibrated model to support. */
    private const PROBABILITY = '/(\d+(?:\.\d+)?\s?%|\bper\s?cent\b|\bpercent\b|\bprobabilit(y|ies)\b|\bodds\b|\bchance\s+of\b|\blikelihood\s+of\s+\d)/i';

    /** Links, domains, email addresses and markup — nothing in a finding may send the reader elsewhere. */
    private const LINKS_OR_MARKUP = '/(https?:|www\.|mailto:|\b[a-z0-9-]+\.(com|org|net|ph|edu|gov|io|info)\b|@[a-z0-9-]+\.|<\/?[a-z]|\[[^\]]*\]\([^)]*\)|\bcontact\b|\bcall\s+\+?\d)/i';

    private const HEDGES = '/\b(may|might|could|possibly|perhaps|suggests?|likely|appears?|seems?|can)\b/i';

    private const CAUSAL = '/\b(because|due\s+to|caused\s+by|leads?\s+to|results?\s+in|as\s+a\s+result\s+of)\b/i';

    private const DERIVED = '/\b(twice|half|double[ds]?|triple[ds]?|majority|most\s+of|minority|one\s+in\s+(two|three|four|five|\d+))\b/i';

    public static function overclaims(string $text): bool
    {
        return preg_match(self::OVERCLAIM, $text) === 1;
    }

    public static function statesProbability(string $text): bool
    {
        return preg_match(self::PROBABILITY, $text) === 1;
    }

    public static function hasLinkOrMarkup(string $text): bool
    {
        return preg_match(self::LINKS_OR_MARKUP, $text) === 1;
    }

    public static function isHedged(string $text): bool
    {
        return preg_match(self::HEDGES, $text) === 1;
    }

    public static function isCausal(string $text): bool
    {
        return preg_match(self::CAUSAL, $text) === 1;
    }

    public static function derivesQuantity(string $text): bool
    {
        return preg_match(self::DERIVED, $text) === 1;
    }

    /**
     * Every number written in the text, as floats ("3", "2.5", "1,240").
     * Ordinal and code-embedded digits (PC-001, LAB-2) are ignored: they are
     * identifiers, not quantities.
     *
     * @return list<float>
     */
    public static function numbers(string $text): array
    {
        $clean = (string) preg_replace('/\b[A-Za-z]+[-_][A-Za-z0-9-]*\d[A-Za-z0-9-]*\b/', ' ', $text);
        $clean = (string) preg_replace('/\bq[1-5]\b/i', ' ', $clean);
        preg_match_all('/(?<![\w.])\d{1,3}(?:,\d{3})+(?:\.\d+)?|(?<![\w.])\d+(?:\.\d+)?/', $clean, $m);

        return array_map(static fn (string $n): float => (float) str_replace(',', '', $n), $m[0]);
    }
}
