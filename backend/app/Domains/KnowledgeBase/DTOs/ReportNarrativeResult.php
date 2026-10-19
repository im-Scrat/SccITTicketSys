<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\Analytics\DTOs\ReportEvidencePacket;
use App\Domains\KnowledgeBase\Services\AdvisoryLanguage;
use App\Enums\ReportQuestion;
use InvalidArgumentException;

/**
 * An AI interpretation, checked statement by statement against the facts it
 * was given (Phase 2.10 R5, SDD DD-95).
 *
 * A finding is **withheld** — never shown, only counted — when it:
 *  - answers a question that was not sufficient, cites nothing, or cites a key
 *    that is unknown or belongs to another question;
 *  - writes a number that is not the value of a fact it cites (decimals within
 *    0.05, and whole-number rounding of a cited decimal, are accepted);
 *  - derives a quantity ("twice", "half", "most of") with no ratio fact behind it;
 *  - claims certainty or states a probability;
 *  - contains a link, domain, email, contact instruction or markup;
 *  - is a possible explanation without a hedge, an observation that asserts a
 *    cause, or rates itself above "low" on facts resting on fewer than 5 records;
 *  - runs past 400 characters, or past the fourth finding for its question.
 *
 * A malformed answer (missing coverage, findings that are not objects) is not
 * withheld piecemeal: it fails the interpretation as a whole.
 */
final readonly class ReportNarrativeResult
{
    public const MAX_TEXT = 400;

    public const MAX_PER_QUESTION = 4;

    public const THIN_EVIDENCE_RECORDS = 5;

    /**
     * @param  array<string, string>  $coverage
     * @param  list<array{question: string, kind: string, text: string, cited_fact_keys: list<string>, confidence: string}>  $findings
     * @param  list<string>  $limitations
     * @param  array<string, int>  $withheldReasons
     */
    private function __construct(
        public array $coverage,
        public array $findings,
        public array $limitations,
        public int $withheld,
        public array $withheldReasons,
    ) {}

    /**
     * @param  array<string, mixed>  $structured
     *
     * @throws InvalidArgumentException when the answer is structurally malformed
     */
    public static function fromStructured(array $structured, ReportEvidencePacket $packet): self
    {
        $coverage = $structured['coverage'] ?? null;
        $findings = $structured['findings'] ?? null;

        if (! is_array($coverage) || ! is_array($findings)) {
            throw new InvalidArgumentException('The interpretation is missing its coverage or findings.');
        }

        $normalisedCoverage = [];
        foreach (ReportQuestion::cases() as $q) {
            $value = $coverage[$q->value] ?? null;
            if (! in_array($value, ['answered', 'insufficient_data'], true)) {
                throw new InvalidArgumentException("Coverage for {$q->value} is missing or invalid.");
            }
            // The application decides sufficiency; the model cannot answer a question the facts do not support.
            $normalisedCoverage[$q->value] = $packet->isSufficient($q->value) ? $value : 'insufficient_data';
        }

        $accepted = [];
        $perQuestion = [];
        $withheld = 0;
        $reasons = [];
        $reject = static function (string $reason) use (&$withheld, &$reasons): void {
            $withheld++;
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        };

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                throw new InvalidArgumentException('A finding is not an object.');
            }

            $question = (string) ($finding['question'] ?? '');
            $kind = (string) ($finding['kind'] ?? '');
            $text = trim((string) ($finding['text'] ?? ''));
            $confidence = (string) ($finding['confidence'] ?? '');
            $keys = array_values(array_unique(array_map('strval', array_filter((array) ($finding['cited_fact_keys'] ?? []), 'is_scalar'))));

            if (ReportQuestion::tryFrom($question) === null || ! in_array($kind, ['observation', 'possible_explanation', 'recommendation'], true) || ! in_array($confidence, ['low', 'medium', 'high'], true)) {
                $reject('invalid_field');

                continue;
            }
            if (! $packet->isSufficient($question)) {
                $reject('insufficient_question');

                continue;
            }
            if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
                $reject('length');

                continue;
            }
            if ($keys === []) {
                $reject('no_citation');

                continue;
            }

            $facts = [];
            foreach ($keys as $key) {
                $fact = $packet->fact($key);
                if ($fact === null || $fact->question !== $question) {
                    $facts = null;
                    break;
                }
                $facts[] = $fact;
            }
            if ($facts === null) {
                $reject('unknown_citation');

                continue;
            }

            if (AdvisoryLanguage::overclaims($text) || AdvisoryLanguage::statesProbability($text)) {
                $reject('certainty_or_probability');

                continue;
            }
            if (AdvisoryLanguage::hasLinkOrMarkup($text)) {
                $reject('link_or_markup');

                continue;
            }

            $values = array_map(static fn ($f) => $f->value, array_filter($facts, static fn ($f): bool => is_int($f->value) || is_float($f->value)));
            foreach (AdvisoryLanguage::numbers($text) as $number) {
                $matched = false;
                foreach ($values as $value) {
                    if (abs($number - (float) $value) <= 0.05 || ($number === floor($number) && abs($number - round((float) $value)) < 0.0001)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    $reject('number_not_in_facts');

                    continue 2;
                }
            }

            $hasRatio = array_filter($facts, static fn ($f): bool => $f->unit === 'ratio') !== [];
            if (AdvisoryLanguage::derivesQuantity($text) && ! $hasRatio) {
                $reject('derived_quantity');

                continue;
            }
            if ($kind === 'possible_explanation' && ! AdvisoryLanguage::isHedged($text)) {
                $reject('unhedged_explanation');

                continue;
            }
            if ($kind === 'observation' && AdvisoryLanguage::isCausal($text)) {
                $reject('causal_observation');

                continue;
            }
            $records = max(array_map(static fn ($f): int => $f->recordCount, $facts));
            if ($confidence !== 'low' && $records < self::THIN_EVIDENCE_RECORDS) {
                $reject('unsupported_confidence');

                continue;
            }
            if (($perQuestion[$question] ?? 0) >= self::MAX_PER_QUESTION) {
                $reject('too_many');

                continue;
            }

            $perQuestion[$question] = ($perQuestion[$question] ?? 0) + 1;
            $accepted[] = ['question' => $question, 'kind' => $kind, 'text' => $text, 'cited_fact_keys' => $keys, 'confidence' => $confidence];
        }

        $limitations = [];
        foreach ((array) ($structured['limitations'] ?? []) as $line) {
            $line = trim((string) (is_scalar($line) ? $line : ''));
            if ($line !== '' && mb_strlen($line) <= self::MAX_TEXT && ! AdvisoryLanguage::hasLinkOrMarkup($line) && ! AdvisoryLanguage::overclaims($line) && ! AdvisoryLanguage::statesProbability($line) && AdvisoryLanguage::numbers($line) === []) {
                $limitations[] = $line;
            }
        }

        return new self($normalisedCoverage, $accepted, array_slice($limitations, 0, 5), $withheld, $reasons);
    }
}
