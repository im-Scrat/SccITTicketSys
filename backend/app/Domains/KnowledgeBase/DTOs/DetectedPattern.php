<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\KnowledgeBase\Services\FailurePatternDetector;
use Carbon\CarbonImmutable;

/**
 * A recurrence found in a PC's completed repairs by
 * {@see FailurePatternDetector} — computed, not generated.
 * Every number here is counted or measured from `maintenance_records`.
 */
final readonly class DetectedPattern
{
    public const KIND_COMPONENT = 'component';

    public const KIND_FAULT_CATEGORY = 'fault_category';

    public const KIND_REPAIR_FREQUENCY = 'repair_frequency';

    /**
     * @param  self::KIND_*  $kind
     * @param  list<int>  $intervalsDays  whole days between consecutive occurrences
     * @param  list<string>  $recordUuids  the maintenance records the pattern was counted from
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $detectedProblem,
        public int $occurrenceCount,
        public array $intervalsDays,
        public CarbonImmutable $firstAt,
        public CarbonImmutable $lastAt,
        public ?int $hardwareComponentId,
        public array $recordUuids,
    ) {}

    /** Mean interval in whole days, or null when there is no interval to average. */
    public function averageDaysBetween(): ?int
    {
        return $this->intervalsDays === []
            ? null
            : (int) round(array_sum($this->intervalsDays) / count($this->intervalsDays));
    }

    /**
     * @return array<string, mixed>
     */
    public function toEvidence(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name,
            'detected_problem' => $this->detectedProblem,
            'occurrence_count' => $this->occurrenceCount,
            'intervals_days' => $this->intervalsDays,
            'average_days_between' => $this->averageDaysBetween(),
            'first_at' => $this->firstAt->toIso8601String(),
            'last_at' => $this->lastAt->toIso8601String(),
            'records' => $this->recordUuids,
        ];
    }
}
