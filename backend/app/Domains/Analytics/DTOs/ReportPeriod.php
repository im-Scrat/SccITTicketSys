<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use App\Domains\Analytics\Exceptions\ReportRangeException;
use App\Enums\ReportComparison;
use App\Enums\ReportGranularity;
use App\Enums\ReportPeriodPreset;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use InvalidArgumentException;

/**
 * A report period: a school-local, half-open range `[start, end)` (Phase 2.10,
 * SDD DD-86; SRS FR-RPT-002).
 *
 * The rules, each of which a test pins:
 *
 *  1. **Half-open, in Asia/Manila.** A ticket filed at exactly midnight belongs
 *     to the day that starts, never to both or neither. The local instants are
 *     converted to UTC once, here, and bound into SQL.
 *  2. **ISO weeks start on Monday**, which is also what PostgreSQL's
 *     `date_trunc('week', …)` does, so PHP and SQL agree by construction.
 *  3. **The current period runs "to date".** `end` is clipped to `asOf` (the
 *     next whole minute). Buckets after it are left out, never zero-filled: a
 *     zero-filled future would read as a drop.
 *  4. **A comparison is another period**, built by {@see comparison()}, with
 *     its own labels and day count — never an implied "same length before".
 */
final readonly class ReportPeriod
{
    /**
     * @param  'previous_unit'|'previous_days'|'weeks_52'|'calendar_year'|null  $shift  how a comparison window was derived; null for the primary period
     */
    public function __construct(
        public ReportPeriodPreset $preset,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $naturalEnd,
        public CarbonImmutable $asOf,
        public ?int $sinceYear = null,
        public ?string $shift = null,
    ) {}

    /**
     * Resolve a preset to its range. Inputs are assumed validated (the report
     * request rejects future anchors, inverted custom ranges and the rest).
     */
    public static function resolve(
        ReportPeriodPreset $preset,
        ?string $anchor,
        ?string $from,
        ?string $to,
        ?int $sinceYear,
        CarbonImmutable $asOf,
    ): self {
        $asOf = $asOf->setTimezone(SchoolClock::TIMEZONE);
        // asOf is the start of the next minute, so the second before it is
        // always "now"'s date — including just before midnight.
        $today = $asOf->subSecond()->startOfDay();
        $anchorDay = $anchor !== null ? SchoolClock::startOfLocalDay($anchor) : $today;

        [$start, $naturalEnd] = match ($preset) {
            ReportPeriodPreset::Day => [$anchorDay, $anchorDay->addDay()],
            ReportPeriodPreset::Week => self::span($anchorDay->startOfWeek(CarbonInterface::MONDAY), fn (CarbonImmutable $s) => $s->addWeek()),
            ReportPeriodPreset::Month => self::span($anchorDay->startOfMonth(), fn (CarbonImmutable $s) => $s->addMonthNoOverflow()),
            ReportPeriodPreset::Year => self::span($anchorDay->startOfYear(), fn (CarbonImmutable $s) => $s->addYear()),
            ReportPeriodPreset::Last7Days,
            ReportPeriodPreset::Last30Days,
            ReportPeriodPreset::Last90Days => [$today->subDays(((int) $preset->rollingDays()) - 1), $today->addDay()],
            ReportPeriodPreset::Custom => [
                SchoolClock::startOfLocalDay($from ?? throw new InvalidArgumentException('A custom period needs a start date.')),
                SchoolClock::startOfLocalDay($to ?? throw new InvalidArgumentException('A custom period needs an end date.'))->addDay(),
            ],
            ReportPeriodPreset::SinceYear => [
                CarbonImmutable::create($sinceYear ?? throw new InvalidArgumentException('Since a year needs the year.'), 1, 1, 0, 0, 0, SchoolClock::TIMEZONE)
                    ?? throw new InvalidArgumentException('Not a valid year.'),
                $today->addDay(),
            ],
        };

        $end = $naturalEnd->lessThan($asOf) ? $naturalEnd : $asOf;

        if (! $start->lessThan($end)) {
            throw new InvalidArgumentException('The period starts after the report runs up to.');
        }

        return new self($preset, $start, $end, $naturalEnd, $asOf, $preset === ReportPeriodPreset::SinceYear ? $sinceYear : null);
    }

    /**
     * @param  Closure(CarbonImmutable): CarbonImmutable  $next
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function span(CarbonImmutable $start, Closure $next): array
    {
        return [$start, $next($start)];
    }

    /* ---------------------------------------------------------- comparison */

    /**
     * The window this period is compared with, or null when there is none.
     *
     * - `previous`: a calendar period steps back one unit to the same point in
     *   time (1–18 Oct to date compares with 1–18 Sep); a rolling or custom one
     *   shifts back by its own length.
     * - `last_year`: day and week periods, and any weekly grouping, shift by
     *   52 weeks (364 days) so a Monday compares with a Monday and ISO week 42
     *   with ISO week 42; everything else shifts by a calendar year, with
     *   29 February becoming the 28th.
     * - "Since a year" is never compared: there is no earlier window of the same
     *   meaning.
     */
    public function comparison(ReportComparison $mode, ReportGranularity $unit): ?self
    {
        if ($mode === ReportComparison::None || $this->preset === ReportPeriodPreset::SinceYear) {
            return null;
        }

        if ($mode === ReportComparison::LastYear) {
            $weeks = in_array($this->preset, [ReportPeriodPreset::Day, ReportPeriodPreset::Week], true)
                || $unit === ReportGranularity::Week;

            return $weeks
                ? $this->shifted(fn (CarbonImmutable $t) => $t->subDays(364), 'weeks_52')
                : $this->shifted(fn (CarbonImmutable $t) => $t->subYearNoOverflow(), 'calendar_year');
        }

        if ($this->preset->isCalendar()) {
            $back = match ($this->preset) {
                ReportPeriodPreset::Day => fn (CarbonImmutable $t) => $t->subDay(),
                ReportPeriodPreset::Week => fn (CarbonImmutable $t) => $t->subWeek(),
                ReportPeriodPreset::Month => fn (CarbonImmutable $t) => $t->subMonthNoOverflow(),
                default => fn (CarbonImmutable $t) => $t->subYearNoOverflow(),
            };
            $shiftedEnd = $back($this->end);
            $end = $this->toDate() && $shiftedEnd->lessThan($this->start) ? $shiftedEnd : $this->start;

            return new self($this->preset, $back($this->start), $end, $this->start, $this->asOf, null, 'previous_unit');
        }

        $days = $this->naturalDays();
        $end = $this->toDate() ? $this->end->subDays($days) : $this->start;

        return new self($this->preset, $this->start->subDays($days), $end, $this->start, $this->asOf, null, 'previous_days');
    }

    /**
     * @param  Closure(CarbonImmutable): CarbonImmutable  $shift
     * @param  'weeks_52'|'calendar_year'  $basis
     */
    private function shifted(Closure $shift, string $basis): self
    {
        return new self($this->preset, $shift($this->start), $shift($this->end), $shift($this->naturalEnd), $this->asOf, null, $basis);
    }

    public function isComparison(): bool
    {
        return $this->shift !== null;
    }

    /* --------------------------------------------------------------- facts */

    /** The full unit or range runs past what has happened yet. */
    public function toDate(): bool
    {
        return $this->end->lessThan($this->naturalEnd);
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->end->utc();
    }

    /** The first school-local date, for date-only drill-down links. */
    public function fromDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    /** The last school-local date the range touches (its end is exclusive). */
    public function toDateInclusive(): string
    {
        return $this->end->subSecond()->format('Y-m-d');
    }

    /**
     * Whether a date-only list (`reported_from`/`reported_to`) reproduces this
     * range exactly: it ends at a local midnight, or it is the primary period
     * running up to now, after which nothing can have been recorded. A
     * comparison cut at "the same point in time" (18 Sep, 04:41) is neither.
     */
    public function expressibleAsDates(): bool
    {
        return $this->end->equalTo($this->end->startOfDay())
            || (! $this->isComparison() && $this->end->equalTo($this->asOf));
    }

    /** Calendar days the range touches; today counts as one. */
    public function days(): int
    {
        $lastDay = $this->end->subSecond()->startOfDay();

        return (int) round($this->start->startOfDay()->diffInDays($lastDay)) + 1;
    }

    /** Days in the whole unit or range, ignoring the to-date clip. */
    public function naturalDays(): int
    {
        return (int) round($this->start->diffInDays($this->naturalEnd));
    }

    /* ---------------------------------------------------------- granularity */

    /**
     * `auto` picks the unit from the period's natural length; an explicit unit
     * is refused when its series would exceed the unit's bucket cap.
     *
     * @throws ReportRangeException
     */
    public function resolveGranularity(ReportGranularity $requested): ReportGranularity
    {
        if ($requested === ReportGranularity::Auto) {
            $span = $this->naturalDays();

            return match (true) {
                $span <= 31 => ReportGranularity::Day,
                $span <= 183 => ReportGranularity::Week,
                $span <= 1096 => ReportGranularity::Month,
                default => ReportGranularity::Year,
            };
        }

        $count = count($this->cursors($requested));
        $max = $requested->maxBuckets();

        if ($count > $max) {
            throw new ReportRangeException(sprintf(
                'Group by %s allows at most %d %s, and this period has %d. Choose a longer unit.',
                $requested->noun(),
                $max,
                $requested->noun($max),
                $count,
            ));
        }

        return $requested;
    }

    /**
     * The series positions, clipped to the period and flagged.
     *
     * @return list<ReportBucket>
     */
    public function buckets(ReportGranularity $unit, ?CarbonImmutable $recordsStart): array
    {
        $unit = $unit === ReportGranularity::Auto ? $this->resolveGranularity($unit) : $unit;
        $spansYears = $this->start->year !== $this->end->subSecond()->year;
        $buckets = [];

        foreach ($this->cursors($unit) as $index => $cursor) {
            $next = self::advance($cursor, $unit);
            $start = $cursor->lessThan($this->start) ? $this->start : $cursor;
            $end = $next->greaterThan($this->end) ? $this->end : $next;

            [$label, $short] = match ($unit) {
                ReportGranularity::Week => [self::rangeText($start, $end), $start->format('j M')],
                ReportGranularity::Month => [$cursor->format('F Y'), $cursor->format($spansYears ? 'M Y' : 'M')],
                ReportGranularity::Year => [$cursor->format('Y'), $cursor->format('Y')],
                default => [$cursor->format('D j M Y'), $cursor->format('j M')],
            };

            $buckets[] = new ReportBucket(
                index: $index,
                key: $cursor->format('Y-m-d'),
                start: $start,
                end: $end,
                label: $label,
                shortLabel: $short,
                partial: ! $start->equalTo($cursor) || ! $end->equalTo($next),
                inProgress: ! $this->isComparison() && $start->lessThanOrEqualTo($this->asOf) && $this->asOf->lessThan($next),
                beforeRecords: $recordsStart !== null && $end->lessThanOrEqualTo($recordsStart),
            );
        }

        return $buckets;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function cursors(ReportGranularity $unit): array
    {
        $cursor = self::truncate($this->start, $unit);
        $cursors = [];

        while ($cursor->lessThan($this->end)) {
            $cursors[] = $cursor;
            $cursor = self::advance($cursor, $unit);
        }

        return $cursors;
    }

    private static function truncate(CarbonImmutable $instant, ReportGranularity $unit): CarbonImmutable
    {
        return match ($unit) {
            ReportGranularity::Week => $instant->startOfWeek(CarbonInterface::MONDAY),
            ReportGranularity::Month => $instant->startOfMonth(),
            ReportGranularity::Year => $instant->startOfYear(),
            default => $instant->startOfDay(),
        };
    }

    private static function advance(CarbonImmutable $cursor, ReportGranularity $unit): CarbonImmutable
    {
        return match ($unit) {
            ReportGranularity::Week => $cursor->addWeek(),
            ReportGranularity::Month => $cursor->addMonthNoOverflow(),
            ReportGranularity::Year => $cursor->addYear(),
            default => $cursor->addDay(),
        };
    }

    /* -------------------------------------------------------------- labels */

    /** "October 2026", "Week of 12 Oct 2026", "Last 30 days", "Since 2025", … */
    public function label(): string
    {
        if ($this->isComparison()) {
            return $this->rangeLabel();
        }

        return match ($this->preset) {
            ReportPeriodPreset::Day => $this->start->format('D j M Y'),
            ReportPeriodPreset::Week => 'Week of '.$this->start->format('j M Y'),
            ReportPeriodPreset::Month => $this->start->format('F Y'),
            ReportPeriodPreset::Year => $this->start->format('Y'),
            ReportPeriodPreset::Custom => self::rangeText($this->start, $this->naturalEnd),
            ReportPeriodPreset::SinceYear => 'Since '.$this->sinceYear,
            default => $this->preset->label(),
        };
    }

    /** The dates actually covered: "1–18 Oct 2026 (to date)". */
    public function rangeLabel(): string
    {
        return self::rangeText($this->start, $this->end).($this->toDate() && ! $this->isComparison() ? ' (to date)' : '');
    }

    /** "ISO week 42, 2025": the honest name of a 52-week comparison. */
    public function isoWeekLabel(): string
    {
        return sprintf('ISO week %d, %d', $this->start->isoWeek(), $this->start->isoWeekYear());
    }

    /**
     * A school-local range in words, end exclusive: "18 Oct 2026",
     * "1–18 Oct 2026", "29 Sep–5 Oct 2026", "29 Dec 2025–4 Jan 2026".
     */
    public static function rangeText(CarbonImmutable $start, CarbonImmutable $endExclusive): string
    {
        $last = $endExclusive->subSecond();

        if ($start->isSameDay($last)) {
            return $start->format('j M Y');
        }

        if ($start->year !== $last->year) {
            return $start->format('j M Y').'–'.$last->format('j M Y');
        }

        if ($start->month !== $last->month) {
            return $start->format('j M').'–'.$last->format('j M Y');
        }

        return $start->format('j').'–'.$last->format('j M Y');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset->value,
            'label' => $this->label(),
            'range_label' => $this->rangeLabel(),
            'from' => $this->fromDate(),
            'to' => $this->toDateInclusive(),
            'starts_at' => $this->startUtc()->toIso8601ZuluString(),
            'ends_at' => $this->endUtc()->toIso8601ZuluString(),
            'to_date' => $this->toDate(),
            'days' => $this->days(),
        ];
    }

    /**
     * The absolute instants, for cache keys and for anything stored to be
     * re-run later (an export queued now must not re-read the clock).
     *
     * @return array<string, string|null>
     */
    public function resolved(): array
    {
        return [
            'preset' => $this->preset->value,
            'start' => $this->startUtc()->toIso8601ZuluString(),
            'end' => $this->endUtc()->toIso8601ZuluString(),
            'natural_end' => $this->naturalEnd->utc()->toIso8601ZuluString(),
            'as_of' => $this->asOf->utc()->toIso8601ZuluString(),
            'shift' => $this->shift,
        ];
    }
}
