<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use Carbon\CarbonImmutable;

/**
 * One position on a report's time axis: a school-local, half-open
 * `[start, end)` slice of the period, already clipped to the period's edges.
 *
 * The `key` is what the SQL side groups by — `to_char(date_trunc(unit,
 * created_at AT TIME ZONE 'Asia/Manila'), 'YYYY-MM-DD')` — so counts are merged
 * onto buckets by key and both sides share one definition of a boundary.
 *
 * The flags are why a bucket is not just a date:
 *  - `partial`: clipped by the period's start or end, so it covers less than a
 *    whole unit (a week that began before the month did);
 *  - `inProgress`: it contains the moment the report runs up to, so its count
 *    is "so far";
 *  - `beforeRecords`: it ends before the first ticket was ever recorded, so its
 *    count is unknown (null), never zero — an empty stretch before go-live must
 *    not read as "nothing broke".
 */
final readonly class ReportBucket
{
    public function __construct(
        public int $index,
        public string $key,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $label,
        public string $shortLabel,
        public bool $partial,
        public bool $inProgress,
        public bool $beforeRecords,
    ) {}

    /** Length in days, for per-day rates: a 2-day partial week weighs 2, not 7. */
    public function days(): float
    {
        return max(1, $this->start->diffInSeconds($this->end)) / 86400;
    }

    /** Neither clipped, nor still running, nor before records began. */
    public function isComplete(): bool
    {
        return ! $this->partial && ! $this->inProgress && ! $this->beforeRecords;
    }

    /**
     * @param  array{path: string, query: array<string, string>}|null  $drilldown
     * @return array<string, mixed>
     */
    public function toArray(?int $count, ?array $drilldown = null): array
    {
        return [
            'index' => $this->index,
            'key' => $this->key,
            'label' => $this->label,
            'short_label' => $this->shortLabel,
            'starts_at' => $this->start->utc()->toIso8601ZuluString(),
            'ends_at' => $this->end->utc()->toIso8601ZuluString(),
            'count' => $this->beforeRecords ? null : $count,
            'partial' => $this->partial,
            'in_progress' => $this->inProgress,
            'before_records' => $this->beforeRecords,
            'drilldown' => $drilldown,
        ];
    }
}
