<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Links from a report figure to the ticket list it counts (Phase 2.10, SDD
 * DD-88, extending DD-81).
 *
 * A link carries `ReportFilters::directoryParams()` — the parameters the figure
 * itself was counted with — so the directory's total equals the figure. A
 * figure the directory cannot reproduce exactly gets **no** link rather than an
 * approximate one: a comparison window cut "at the same point in time"
 * (18 Sep, 04:41) cannot be said in dates, and event counts such as "resolved
 * in period" are not a list of reported tickets at all.
 */
final class TicketDrilldown
{
    public const PATH = '/app/tickets/manage';

    /**
     * @param  array<string, string|null>  $overrides  see ReportFilters::directoryParams()
     * @return array{path: string, query: array<string, string>}|null
     */
    public static function for(ReportFilters $filters, array $overrides = [], ?ReportPeriod $range = null): ?array
    {
        $range ??= $filters->period;

        if (! $range->expressibleAsDates()) {
            return null;
        }

        return self::link($filters->directoryParams($range, $overrides));
    }

    /**
     * A link for one time bucket of a period.
     *
     * @param  array<string, string|null>  $overrides
     * @return array{path: string, query: array<string, string>}|null
     */
    public static function forBucket(
        ReportFilters $filters,
        ReportPeriod $range,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $overrides = [],
    ): ?array {
        $exact = $end->equalTo($end->startOfDay())
            || (! $range->isComparison() && $end->equalTo($range->asOf));

        if (! $exact) {
            return null;
        }

        return self::link($filters->directoryParams($range, [
            ...$overrides,
            'reported_from' => $start->format('Y-m-d'),
            'reported_to' => $end->subSecond()->format('Y-m-d'),
        ]));
    }

    /**
     * @param  array<string, string>  $params
     * @return array{path: string, query: array<string, string>}
     */
    private static function link(array $params): array
    {
        return ['path' => self::PATH, 'query' => ['tab' => 'directory', ...$params]];
    }
}
