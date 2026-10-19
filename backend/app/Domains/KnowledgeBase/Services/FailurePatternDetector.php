<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\CompletedRepair;
use App\Domains\KnowledgeBase\DTOs\DetectedPattern;
use App\Domains\KnowledgeBase\DTOs\PcHistorySnapshot;
use Carbon\CarbonImmutable;

/**
 * Finds recurrences in a PC's completed corrective repairs (WP-L; SRS
 * FR-AI-012) — **by counting, not by asking a model.**
 *
 * Whether a pattern exists decides whether a prediction is attempted at all,
 * and a model asked "is there a pattern?" will usually find one. So the
 * detection is deterministic and conservative, every figure it produces is
 * measured from the records, and the model is only ever asked to interpret a
 * pattern this class has already established.
 *
 * Three recurrences, each over corrective repairs only (a preventive visit is
 * not a failure):
 *
 * - **component** — the same kind of component replaced in at least
 *   {@see MIN_COMPONENT_RECURRENCE} separate repairs;
 * - **fault category** — repairs linked to tickets of the same category at
 *   least {@see MIN_CATEGORY_RECURRENCE} times;
 * - **repair frequency** — at least {@see FREQUENT_REPAIR_COUNT} corrective
 *   repairs inside {@see FREQUENT_REPAIR_WINDOW_DAYS} days.
 *
 * The thresholds are deliberately low-bar *gates*, not statistics: they decide
 * that there is something worth describing, and nothing more. What is claimed
 * about the future is limited separately — see {@see windowDays()}.
 */
class FailurePatternDetector
{
    public const MIN_COMPONENT_RECURRENCE = 2;

    public const MIN_CATEGORY_RECURRENCE = 2;

    public const FREQUENT_REPAIR_COUNT = 3;

    public const FREQUENT_REPAIR_WINDOW_DAYS = 365;

    /** Occurrences needed before any time window is stated: two intervals, not one. */
    public const MIN_OCCURRENCES_FOR_WINDOW = 3;

    /** Longest interval may be at most this multiple of the shortest for a window to be stated. */
    public const MAX_INTERVAL_SPREAD = 3;

    /**
     * @return list<DetectedPattern> strongest first
     */
    public function detect(PcHistorySnapshot $history, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $corrective = $history->correctiveRepairs();

        $patterns = [
            ...$this->componentPatterns($corrective),
            ...$this->categoryPatterns($corrective),
            ...$this->frequencyPattern($corrective, $now),
        ];

        // Strongest first: most occurrences, then most recent. The first entry
        // is the one a prediction is anchored to.
        usort($patterns, static fn (DetectedPattern $a, DetectedPattern $b): int => [$b->occurrenceCount, $b->lastAt->getTimestamp()] <=> [$a->occurrenceCount, $a->lastAt->getTimestamp()]);

        return $patterns;
    }

    /**
     * The days until the pattern's typical interval next elapses — or null
     * whenever the history cannot honestly support stating one.
     *
     * Null when: fewer than {@see MIN_OCCURRENCES_FOR_WINDOW} occurrences (one
     * interval is an anecdote, not a rhythm); the intervals are too irregular
     * (longest more than {@see MAX_INTERVAL_SPREAD}× the shortest); or the
     * typical interval has already passed since the last occurrence (the
     * history then says "overdue by its own rhythm", not "within N days").
     *
     * A conservative heuristic, not a survival model. It is never taken from
     * the AI: a model asked for a time window will always produce one.
     */
    public function windowDays(DetectedPattern $pattern, ?CarbonImmutable $now = null): ?int
    {
        $now ??= CarbonImmutable::now();
        $intervals = $pattern->intervalsDays;

        if ($pattern->occurrenceCount < self::MIN_OCCURRENCES_FOR_WINDOW || count($intervals) < 2) {
            return null;
        }

        $shortest = min($intervals);
        if ($shortest <= 0 || max($intervals) > self::MAX_INTERVAL_SPREAD * $shortest) {
            return null;
        }

        $remaining = (int) $pattern->averageDaysBetween() - (int) $pattern->lastAt->diffInDays($now);

        return $remaining > 0 ? $remaining : null;
    }

    /**
     * @param  list<CompletedRepair>  $corrective
     * @return list<DetectedPattern>
     */
    private function componentPatterns(array $corrective): array
    {
        /** @var array<string, array{label: string, repairs: array<int, CompletedRepair>, component_ids: array<int, true>}> $byType */
        $byType = [];

        foreach ($corrective as $repair) {
            foreach ($repair->replacements as $replacement) {
                $type = $replacement['component_type'];
                $byType[$type] ??= ['label' => $replacement['component_label'], 'repairs' => [], 'component_ids' => []];
                // Keyed by record: two PSUs swapped in one visit is one occurrence.
                $byType[$type]['repairs'][$repair->id] = $repair;

                if ($replacement['component_id'] !== null) {
                    $byType[$type]['component_ids'][$replacement['component_id']] = true;
                }
            }
        }

        $patterns = [];

        foreach ($byType as $group) {
            $repairs = array_values($group['repairs']);

            if (count($repairs) < self::MIN_COMPONENT_RECURRENCE) {
                continue;
            }

            // Only name a specific component when every replacement used the same one.
            $componentIds = array_keys($group['component_ids']);

            $patterns[] = $this->pattern(
                DetectedPattern::KIND_COMPONENT,
                "Recurring {$group['label']} replacement",
                "{$group['label']} replaced in ".count($repairs).' separate repairs',
                $repairs,
                count($componentIds) === 1 ? $componentIds[0] : null,
            );
        }

        return $patterns;
    }

    /**
     * @param  list<CompletedRepair>  $corrective
     * @return list<DetectedPattern>
     */
    private function categoryPatterns(array $corrective): array
    {
        /** @var array<string, list<CompletedRepair>> $byCategory */
        $byCategory = [];

        foreach ($corrective as $repair) {
            if ($repair->ticketCategory !== null) {
                $byCategory[$repair->ticketCategory][] = $repair;
            }
        }

        $patterns = [];

        foreach ($byCategory as $category => $repairs) {
            if (count($repairs) < self::MIN_CATEGORY_RECURRENCE) {
                continue;
            }

            $patterns[] = $this->pattern(
                DetectedPattern::KIND_FAULT_CATEGORY,
                "Recurring {$category} faults",
                count($repairs)." repairs of reported {$category} faults",
                $repairs,
                null,
            );
        }

        return $patterns;
    }

    /**
     * @param  list<CompletedRepair>  $corrective
     * @return list<DetectedPattern>
     */
    private function frequencyPattern(array $corrective, CarbonImmutable $now): array
    {
        $since = $now->subDays(self::FREQUENT_REPAIR_WINDOW_DAYS);
        $recent = array_values(array_filter(
            $corrective,
            static fn (CompletedRepair $repair): bool => $repair->completedAt->greaterThanOrEqualTo($since),
        ));

        if (count($recent) < self::FREQUENT_REPAIR_COUNT) {
            return [];
        }

        return [$this->pattern(
            DetectedPattern::KIND_REPAIR_FREQUENCY,
            'Frequent corrective repairs',
            count($recent).' corrective repairs in the last '.self::FREQUENT_REPAIR_WINDOW_DAYS.' days',
            $recent,
            null,
        )];
    }

    /**
     * @param  DetectedPattern::KIND_*  $kind
     * @param  list<CompletedRepair>  $repairs  chronological
     */
    private function pattern(string $kind, string $name, string $problem, array $repairs, ?int $componentId): DetectedPattern
    {
        $intervals = [];
        for ($i = 1, $n = count($repairs); $i < $n; $i++) {
            $intervals[] = (int) $repairs[$i - 1]->completedAt->diffInDays($repairs[$i]->completedAt);
        }

        return new DetectedPattern(
            kind: $kind,
            name: $name,
            detectedProblem: $problem,
            occurrenceCount: count($repairs),
            intervalsDays: $intervals,
            firstAt: $repairs[0]->completedAt,
            lastAt: $repairs[count($repairs) - 1]->completedAt,
            hardwareComponentId: $componentId,
            recordUuids: array_map(static fn (CompletedRepair $repair): string => $repair->uuid, $repairs),
        );
    }
}
