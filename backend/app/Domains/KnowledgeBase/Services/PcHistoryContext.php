<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Agents\PcFailurePredictionAgent;
use App\Domains\KnowledgeBase\DTOs\CompletedRepair;
use App\Domains\KnowledgeBase\DTOs\DetectedPattern;
use App\Domains\KnowledgeBase\DTOs\PcHistorySnapshot;

/**
 * Assembles the prompt {@see PcFailurePredictionAgent} reads (WP-L).
 *
 * Two kinds of content, kept visibly apart:
 *
 * - **FACTS** — counts, dates, intervals and pattern names this application
 *   computed itself ({@see FailurePatternDetector}). They are stated plainly so
 *   the model has exact numbers to cite and no reason to estimate its own.
 * - **Records** — everything a person typed (diagnoses, root causes,
 *   resolutions, notes, ticket titles, the PC's own name) goes through
 *   {@see PromptGuard::wrapUntrustedData()} as DATA.
 *
 * Bounded: the most recent {@see MAX_REPAIRS} repairs, each free-text field
 * clipped, and older repairs dropped until the whole prompt fits the
 * input-size guard. The FACTS still describe the full history — only the prose
 * behind them is trimmed.
 */
class PcHistoryContext
{
    private const MAX_REPAIRS = 15;

    private const FIELD_CHARS = 400;

    private const NOTE_CHARS = 300;

    /** Head-room under `ai.sccit.max_input_chars` for the wrapper text itself. */
    private const RESERVED_CHARS = 1500;

    /**
     * @param  list<DetectedPattern>  $patterns  strongest first
     */
    public function buildPrompt(PcHistorySnapshot $history, array $patterns, ?int $windowDays): string
    {
        $facts = $this->facts($history, $patterns, $windowDays);
        $identity = PromptGuard::wrapUntrustedData('PC name', (string) $history->pcUnit->pc_name);

        $repairs = array_slice($history->repairs, -self::MAX_REPAIRS);
        $budget = (int) config('ai.sccit.max_input_chars', 20000) - self::RESERVED_CHARS;

        do {
            $prompt = implode("\n\n", [
                $facts,
                $identity,
                PromptGuard::wrapUntrustedData('completed maintenance history', $this->records($repairs)),
            ]);

            if (mb_strlen($prompt) <= $budget || count($repairs) <= 1) {
                return $prompt;
            }

            array_shift($repairs); // drop the oldest prose first
        } while (true);
    }

    /**
     * @param  list<DetectedPattern>  $patterns
     */
    private function facts(PcHistorySnapshot $history, array $patterns, ?int $windowDays): string
    {
        $corrective = count($history->correctiveRepairs());
        $lines = [
            'FACTS (computed by the application from its completed maintenance records):',
            '- PC unit code: '.$history->pcUnit->unit_code,
            '- Completed maintenance records: '.count($history->repairs)." ({$corrective} corrective, ".(count($history->repairs) - $corrective).' preventive)',
            '- First completed: '.($history->firstCompletedAt()?->toDateString() ?? 'none'),
            '- Most recent completed: '.($history->lastCompletedAt()?->toDateString() ?? 'none'),
            '- Detected patterns (strongest first):',
        ];

        foreach ($patterns as $pattern) {
            $average = $pattern->averageDaysBetween();
            $lines[] = sprintf(
                '  * %s — %d occurrences, %s to %s%s',
                $pattern->name,
                $pattern->occurrenceCount,
                $pattern->firstAt->toDateString(),
                $pattern->lastAt->toDateString(),
                $average !== null ? ', intervals (days): '.implode(', ', $pattern->intervalsDays).", average {$average}" : '',
            );
        }

        $lines[] = $windowDays !== null
            ? "- Time window supported by the history: the strongest pattern's typical interval next elapses in about {$windowDays} days."
            : '- Time window supported by the history: none. Do not state one.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<CompletedRepair>  $repairs
     */
    private function records(array $repairs): string
    {
        $blocks = [];

        foreach ($repairs as $repair) {
            $lines = ["Repair completed {$repair->completedAt->toDateString()} ({$repair->typeName})"];

            foreach ([
                'Reported fault' => $repair->ticketTitle !== null ? trim(($repair->ticketCategory ?? '').': '.$repair->ticketTitle, ': ') : null,
                'Diagnosis' => $repair->diagnosis,
                'Root cause' => $repair->rootCause,
                'Resolution' => $repair->resolution,
                'Recommendation recorded' => $repair->preventiveRecommendation,
            ] as $label => $value) {
                if ($value !== null && trim($value) !== '') {
                    $lines[] = "{$label}: ".$this->clip($value, self::FIELD_CHARS);
                }
            }

            foreach ($repair->replacements as $replacement) {
                $lines[] = sprintf(
                    'Replaced: %s%s ×%d%s',
                    $replacement['component_label'],
                    $replacement['component_name'] !== null ? " ({$this->clip($replacement['component_name'], 80)})" : '',
                    $replacement['quantity'],
                    $replacement['reason'] !== null ? ' — '.$this->clip($replacement['reason'], 160) : '',
                );
            }

            foreach ($repair->notes as $note) {
                $lines[] = 'Note: '.$this->clip($note, self::NOTE_CHARS);
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private function clip(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
