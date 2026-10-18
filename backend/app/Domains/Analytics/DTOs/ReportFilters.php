<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use App\Enums\ReportComparison;
use App\Enums\ReportGranularity;
use App\Enums\ReportPeriodPreset;
use Carbon\CarbonImmutable;

/**
 * A validated report filter set (Phase 2.10, SDD DD-88).
 *
 * Different parameters are ANDed; the values inside one list are ORed. Status
 * means the ticket's **current** status; problem area means its current
 * category's area; room, building and room type mean the room the ticket was
 * **reported** in (the stored room, archived rooms included).
 *
 * {@see directoryParams()} is the heart of it: the parameter set report figures
 * aggregate over (through `TicketDirectoryQuery::reportScope()`) and the one a
 * figure's drill-down link carries into the ticket directory. One definition,
 * two consumers — so a count and its list cannot disagree.
 */
final readonly class ReportFilters
{
    /**
     * @param  list<string>  $statuses  sorted status slugs
     * @param  list<string>  $categories  sorted category slugs
     * @param  list<string>  $problemAreas  sorted problem areas
     */
    public function __construct(
        public ReportPeriod $period,
        public ?ReportPeriod $comparison,
        public ReportComparison $compareMode,
        public ReportGranularity $granularity,
        public ReportGranularity $requestedGranularity,
        public ?string $anchor,
        public ?string $roomType,
        public ?string $building,
        public ?string $room,
        public array $statuses,
        public array $categories,
        public array $problemAreas,
    ) {}

    /**
     * Build from a validated request payload.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, CarbonImmutable $asOf): self
    {
        $preset = ReportPeriodPreset::from((string) ($validated['period'] ?? ReportPeriodPreset::Month->value));
        $anchor = $preset->isCalendar() ? self::string($validated['anchor'] ?? null) : null;

        $period = ReportPeriod::resolve(
            $preset,
            $anchor,
            self::string($validated['from'] ?? null),
            self::string($validated['to'] ?? null),
            isset($validated['since_year']) ? (int) $validated['since_year'] : null,
            $asOf,
        );

        $requested = ReportGranularity::from((string) ($validated['granularity'] ?? ReportGranularity::Auto->value));
        $granularity = $period->resolveGranularity($requested);

        // "Since a year" has no earlier window of the same meaning; the request
        // refuses an explicit comparison, and an absent one means none.
        $compareMode = $preset === ReportPeriodPreset::SinceYear
            ? ReportComparison::None
            : ReportComparison::from((string) ($validated['compare'] ?? ReportComparison::Previous->value));

        return new self(
            period: $period,
            comparison: $period->comparison($compareMode, $granularity),
            compareMode: $compareMode,
            granularity: $granularity,
            requestedGranularity: $requested,
            // Canonical: a month anchored on the 17th is the same report as one
            // anchored on the 1st, so links and cache keys use the period start.
            anchor: $anchor !== null ? $period->fromDate() : null,
            roomType: self::string($validated['room_type'] ?? null),
            building: self::string($validated['building'] ?? null),
            room: self::string($validated['room'] ?? null),
            statuses: self::list($validated['status'] ?? null),
            categories: self::list($validated['category'] ?? null),
            problemAreas: self::list($validated['problem_area'] ?? null),
        );
    }

    /** A current-status filter is on (it changes what comparisons can mean). */
    public function hasStatusFilter(): bool
    {
        return $this->statuses !== [];
    }

    /** The filters that narrow by location, category or area — never by time or status. */
    public function hasLocationFilter(): bool
    {
        return $this->roomType !== null || $this->building !== null || $this->room !== null;
    }

    /**
     * Location, category and problem area: the scope every ticket figure shares,
     * including the lifecycle figures that ignore the report date and status.
     *
     * @return array<string, string>
     */
    public function scopeParams(): array
    {
        return array_filter([
            'room_type' => $this->roomType,
            'building' => $this->building,
            'room' => $this->room,
            'category' => $this->categories !== [] ? implode(',', $this->categories) : null,
            'problem_area' => $this->problemAreas !== [] ? implode(',', $this->problemAreas) : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * The ticket-directory parameters for a range (default: the period): the
     * population report figures count, and what their drill-down links carry.
     * `$overrides` replace or add keys (a status slice, `exclude_duplicates`);
     * a null override removes the key.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    public function directoryParams(?ReportPeriod $range = null, array $overrides = []): array
    {
        $range ??= $this->period;

        $params = [
            ...$this->scopeParams(),
            'reported_from' => $range->fromDate(),
            'reported_to' => $range->toDateInclusive(),
        ];

        if ($this->statuses !== []) {
            $params['status'] = implode(',', $this->statuses);
        }

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        ksort($params);

        return $params;
    }

    /**
     * The canonical API parameters (defaults omitted), for deep links back to
     * the same report.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = array_filter([
            'period' => $this->period->preset->value,
            'anchor' => $this->anchor,
            'from' => $this->period->preset === ReportPeriodPreset::Custom ? $this->period->fromDate() : null,
            'to' => $this->period->preset === ReportPeriodPreset::Custom ? $this->period->naturalEnd->subDay()->format('Y-m-d') : null,
            'since_year' => $this->period->sinceYear !== null ? (string) $this->period->sinceYear : null,
            'granularity' => $this->requestedGranularity !== ReportGranularity::Auto ? $this->requestedGranularity->value : null,
            'compare' => $this->compareMode !== ReportComparison::Previous ? $this->compareMode->value : null,
            'room_type' => $this->roomType,
            'building' => $this->building,
            'room' => $this->room,
            'status' => $this->statuses !== [] ? implode(',', $this->statuses) : null,
            'category' => $this->categories !== [] ? implode(',', $this->categories) : null,
            'problem_area' => $this->problemAreas !== [] ? implode(',', $this->problemAreas) : null,
        ], static fn (?string $value): bool => $value !== null);

        ksort($query);

        return $query;
    }

    /**
     * Everything that changes a report's numbers, as absolute instants: a cached
     * payload is keyed on what it counted, not on the words that asked for it
     * ("this month" means something else tomorrow).
     *
     * @return array<string, mixed>
     */
    public function cacheKeyParts(): array
    {
        return [
            'period' => $this->period->resolved(),
            'comparison' => $this->comparison?->resolved(),
            'granularity' => $this->granularity->value,
            'scope' => $this->scopeParams(),
            'status' => $this->statuses,
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
        sort($items);

        return $items;
    }
}
