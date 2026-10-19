<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

/**
 * The numbers contract and metric dictionary every report surface shares
 * (Phase 2.10, SDD DD-88): the dashboard, Overall Reports, exports and the AI
 * evidence all round, compare and define figures through this one class, so
 * the same figure never reads differently in two places.
 *
 *  - **Shares** have one decimal place. A distribution uses the largest
 *    remainder method, so its shares add up to exactly 100.0 (two equal counts
 *    can then differ by 0.1, and the definitions say so).
 *  - **A percentage change** is a whole number, given only when the earlier
 *    figure is at least {@see MIN_BASE_FOR_PERCENT}. "Up 200%" on a move from 1
 *    to 3 tickets is arithmetic, not information; the absolute change is
 *    always given.
 *  - **Daily averages** have one decimal place.
 */
final class ReportMetrics
{
    public const MIN_BASE_FOR_PERCENT = 5;

    /**
     * The change from an earlier figure. Null fields when there is no earlier
     * figure; `change_pct` null when the base is too small to mean anything.
     *
     * @return array{change: int|null, change_pct: int|null, direction: 'up'|'down'|'same'|null}
     */
    public static function change(int $current, ?int $previous): array
    {
        if ($previous === null) {
            return ['change' => null, 'change_pct' => null, 'direction' => null];
        }

        $difference = $current - $previous;

        return [
            'change' => $difference,
            'change_pct' => $previous >= self::MIN_BASE_FOR_PERCENT
                ? (int) round($difference / $previous * 100, 0, PHP_ROUND_HALF_UP)
                : null,
            'direction' => $difference > 0 ? 'up' : ($difference < 0 ? 'down' : 'same'),
        ];
    }

    /** A part of a whole as a percentage, one decimal place; null when the whole is zero. */
    public static function share(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1, PHP_ROUND_HALF_UP) : null;
    }

    /** Per day, one decimal place. */
    public static function perDay(int $count, int|float $days): float
    {
        return round($count / max(1, $days), 1, PHP_ROUND_HALF_UP);
    }

    /**
     * Percentages to one decimal place that add up to exactly 100.0: each share
     * is floored to tenths and the tenths left over go to the largest
     * remainders, ties to the earlier key (callers pass display order).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $counts
     * @return array<TKey, float|null> null for every key when the total is zero
     */
    public static function largestRemainder(array $counts): array
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return array_map(static fn (): ?float => null, $counts);
        }

        $tenths = [];
        $remainders = [];

        foreach ($counts as $key => $count) {
            $exact = $count * 1000 / $total;
            $tenths[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $tenths[$key];
        }

        $keys = array_keys($counts);
        $position = array_flip($keys);
        usort($keys, static fn ($a, $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($position[$a] <=> $position[$b]));

        foreach (array_slice($keys, 0, 1000 - array_sum($tenths)) as $key) {
            $tenths[$key]++;
        }

        return array_map(static fn (int $value): float => $value / 10, $tenths);
    }

    /**
     * The definitions behind the ticket figures, for the dashboard's
     * "How each figure is counted" disclosure and the export's notes.
     *
     * @return list<array{term: string, text: string}>
     */
    public static function ticketDefinitions(): array
    {
        return [
            ['term' => 'Reported', 'text' => 'Tickets filed during the period, by school time (Asia/Manila), whatever happened to them since. Duplicates and cancelled tickets are included and named; archived (deleted) tickets are not.'],
            ['term' => 'Problems', 'text' => 'Reported tickets that are neither a duplicate of another report nor cancelled. Rooms and room types are ranked by problems, so a fault reported twice counts once.'],
            ['term' => 'Still open', 'text' => "Reported tickets whose current status is Open, Assigned, In progress, On hold, or Resolved and awaiting the reporter's confirmation."],
            ['term' => 'Resolved in period', 'text' => 'Tickets that moved to Resolved during the period, whenever they were reported, each counted once. The location and category filters apply; the status filter does not.'],
            ['term' => 'Daily average', 'text' => 'Reported tickets divided by the calendar days the period covers; today counts as a whole day.'],
            ['term' => 'Location', 'text' => 'The room a ticket was reported in, as recorded when it was filed, including rooms archived since. A PC may have moved since then.'],
            ['term' => 'Status', 'text' => "Each ticket's current status. A status filter shows tickets reported in the period that are in that status now, so periods are not compared while it is on: older tickets have had longer to move on."],
            ['term' => 'Percentages', 'text' => 'Shares have one decimal place and are rounded so they add up to exactly 100%; two equal counts can then differ by 0.1. A percentage change is given only when the earlier figure is at least 5.'],
            ['term' => 'Weeks and comparisons', 'text' => 'Weeks run Monday to Sunday. "Previous period" compares with the same span just before; "same period last year" uses 52 weeks earlier for days and weeks, so weekdays line up, and the calendar year otherwise.'],
        ];
    }
}
