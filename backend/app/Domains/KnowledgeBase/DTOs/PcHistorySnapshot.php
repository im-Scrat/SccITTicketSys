<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Models\PcUnit;
use Carbon\CarbonImmutable;

/**
 * A PC's completed repair history at one moment (WP-L) — oldest first.
 */
final readonly class PcHistorySnapshot
{
    /**
     * @param  list<CompletedRepair>  $repairs  chronological, oldest first
     */
    public function __construct(
        public PcUnit $pcUnit,
        public array $repairs,
    ) {}

    /**
     * Corrective work only — a preventive visit is care, not a failure, and
     * counting it as one would manufacture a failure pattern out of diligence.
     *
     * @return list<CompletedRepair>
     */
    public function correctiveRepairs(): array
    {
        return array_values(array_filter(
            $this->repairs,
            static fn (CompletedRepair $repair): bool => ! $repair->isPreventive,
        ));
    }

    public function firstCompletedAt(): ?CarbonImmutable
    {
        return $this->repairs[0]->completedAt ?? null;
    }

    public function lastCompletedAt(): ?CarbonImmutable
    {
        return $this->repairs === [] ? null : $this->repairs[array_key_last($this->repairs)]->completedAt;
    }
}
