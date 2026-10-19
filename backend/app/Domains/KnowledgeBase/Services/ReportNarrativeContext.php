<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Analytics\DTOs\ReportEvidencePacket;
use App\Domains\KnowledgeBase\Agents\ReportNarrativeAgent;

/**
 * Builds the prompt for {@see ReportNarrativeAgent} (Phase 2.10 R5, SDD DD-95).
 *
 * The facts go in the **prompt**, never in `instructions()` — so the
 * instructions are identical for every report and the facts pass through
 * SafeAgentInvoker's redaction and size guard like every other prompt. The
 * scope and facts carry codes typed by administrators, so they are wrapped as
 * untrusted data. If the prompt would pass the budget, facts are dropped from
 * the end of each question, deterministically, never mid-fact.
 */
final class ReportNarrativeContext
{
    /** Below PromptGuard's 20,000-character limit, with room for the wrapper. */
    public const BUDGET = 18000;

    public function build(ReportEvidencePacket $packet): string
    {
        $data = $packet->toArray();

        foreach ([null, 8, 5, 3, 1] as $cap) {
            $questions = [];
            foreach ($data['questions'] as $key => $q) {
                $questions[$key] = $q['sufficient']
                    ? ['question' => $q['question'], 'sufficient' => true, 'facts' => $cap === null ? $q['facts'] : array_slice($q['facts'], 0, $cap)]
                    : ['question' => $q['question'], 'sufficient' => false, 'reason' => $q['reason']];
            }

            $prompt = $this->render($data['scope'], $questions, $data['limitations']);

            if (mb_strlen($prompt) <= self::BUDGET) {
                return $prompt;
            }
        }

        return mb_substr($prompt, 0, self::BUDGET);
    }

    /**
     * @param  array<string, mixed>  $questions
     * @param  list<string>  $limitations
     */
    private function render(string $scope, array $questions, array $limitations): string
    {
        $facts = json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return implode("\n\n", [
            'Interpret the overall report below. Answer only the questions marked "sufficient": true.',
            'SCOPE',
            PromptGuard::wrapUntrustedData('report scope', $scope),
            'FACTS (computed by the application; authoritative)',
            PromptGuard::wrapUntrustedData('report facts', $facts),
            'LIMITATIONS (stated by the application)',
            implode("\n", array_map(static fn (string $l): string => '- '.$l, $limitations)),
        ]);
    }
}
