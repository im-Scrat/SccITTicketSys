<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportBucket;
use App\Enums\ReportGranularity;

/**
 * The plain-language summary of a ticket overview (Phase 2.10, SDD DD-88).
 *
 * Fixed rules over the payload's own numbers — **not AI**. The dashboard,
 * exports and email print these sentences word for word, and the interface
 * labels them "Calculated from recorded tickets" so they are never mistaken
 * for an interpretation.
 *
 * The rules that keep them honest:
 *  - with a current-status filter on, periods are not compared at all: an older
 *    period's tickets have had longer to move on, so "fewer are open than last
 *    month" would be produced by the filter, not by the school;
 *  - a percentage change appears only on a base of at least 5;
 *  - the trend and the peak use complete buckets only, on per-day rates, so a
 *    two-day first week neither looks quiet nor wins "busiest".
 */
final class TicketOverviewSummary
{
    /**
     * @param  array{
     *     period_label: string,
     *     comparison_label: string|null,
     *     comparison: 'on'|'off'|'status_filter'|'before_records',
     *     comparison_partial: bool,
     *     records_start: string|null,
     *     starts_before_records: bool,
     *     reported: int,
     *     previous: int|null,
     *     days: int,
     *     previous_days: int|null,
     *     unit: ReportGranularity,
     *     buckets: list<ReportBucket>,
     *     counts: array<string, int>,
     *     open: int,
     *     awaiting: int,
     *     duplicates: int,
     *     cancelled: int,
     *     without_history: int,
     *     locations: array{top: list<array{label: string, count: int}>, located: int, all_scope: bool, types: array<string, array{count: int, per_room: float|null}>, unrecorded: int}|null,
     * }  $facts
     * @return array{sentences: list<array{key: string, text: string}>, caveats: list<string>}
     */
    public static function build(array $facts): array
    {
        $sentences = [];
        $caveats = [];

        $comparison = self::comparison($facts, $caveats);

        if ($comparison !== null) {
            $sentences[] = ['key' => 'comparison', 'text' => $comparison];
        }

        // A trend over a current-status cohort is the filter's artefact too.
        if ($facts['comparison'] !== 'status_filter') {
            $trend = self::trend($facts);

            if ($trend !== null) {
                $sentences[] = ['key' => 'trend', 'text' => $trend];
            }
        }

        $peak = self::peak($facts);

        if ($peak !== null) {
            $sentences[] = ['key' => 'peak', 'text' => $peak];
        }

        foreach (self::locations($facts) as $key => $text) {
            $sentences[] = ['key' => $key, 'text' => $text];
        }

        if ($facts['reported'] > 0) {
            $sentences[] = ['key' => 'status', 'text' => $facts['awaiting'] > 0
                ? sprintf("%s of the %s are still open, including %s resolved and awaiting the reporter's confirmation.", $facts['open'], self::tickets($facts['reported']), $facts['awaiting'])
                : sprintf('%s of the %s are still open.', $facts['open'], self::tickets($facts['reported'])),
            ];
        }

        if ($facts['starts_before_records'] && $facts['records_start'] !== null) {
            $sentences[] = ['key' => 'records_start', 'text' => "Records begin on {$facts['records_start']}; nothing earlier was recorded in this system."];
        }

        if ($facts['duplicates'] > 0 || $facts['cancelled'] > 0) {
            $caveats[] = sprintf(
                'Reported includes %s and %s; the room ranking counts problems and leaves both out.',
                self::plural($facts['duplicates'], 'duplicate'),
                self::plural($facts['cancelled'], 'cancelled ticket'),
            );
        }

        if ($facts['without_history'] > 0) {
            $caveats[] = sprintf('%s no status history (for example, records entered before tracking began).', self::plural($facts['without_history'], 'ticket has', 'tickets have'));
        }

        return ['sentences' => $sentences, 'caveats' => $caveats];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $caveats
     */
    private static function comparison(array $facts, array &$caveats): ?string
    {
        $period = $facts['period_label'];
        $other = $facts['comparison_label'];
        $current = (int) $facts['reported'];

        return match ($facts['comparison']) {
            'off' => null,
            'status_filter' => 'Not compared: a current-status filter favours recent periods, because older tickets have had longer to move on.',
            'before_records' => "Not compared: records begin on {$facts['records_start']}, after {$other} ended.",
            default => self::change($period, (string) $other, $current, (int) $facts['previous'], $facts, $caveats),
        };
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $caveats
     */
    private static function change(string $period, string $other, int $current, int $previous, array $facts, array &$caveats): string
    {
        if ($current === 0 && $previous === 0) {
            return "No tickets were reported in {$period} or in {$other}.";
        }

        $change = ReportMetrics::change($current, $previous);
        $percent = $facts['comparison_partial'] ? null : $change['change_pct'];

        if ($facts['comparison_partial']) {
            $caveats[] = "The comparison period is only partly covered by records (records begin {$facts['records_start']}), so no percentage is given.";
        } elseif (max($current, $previous) < 10) {
            $caveats[] = 'Small numbers: a change of one or two tickets moves any percentage a lot.';
        }

        if ($facts['previous_days'] !== null && $facts['previous_days'] !== $facts['days'] && ! $facts['comparison_partial']) {
            $caveats[] = sprintf(
                'The periods cover %d and %d days; daily averages %s and %s.',
                $facts['days'],
                $facts['previous_days'],
                ReportMetrics::perDay($current, (int) $facts['days']),
                ReportMetrics::perDay($previous, (int) $facts['previous_days']),
            );
        }

        $reported = sprintf('%s reported in %s', ucfirst(self::tickets($current)), $period);

        if ($previous === 0) {
            return "{$reported}; none in {$other}.";
        }

        if ($change['direction'] === 'same') {
            return "{$reported}, the same as in {$other}.";
        }

        $difference = abs((int) $change['change']);
        $word = $change['direction'] === 'up' ? 'more' : 'fewer';
        $trailer = $percent !== null ? sprintf(', %s %d%%', $change['direction'] === 'up' ? 'up' : 'down', abs($percent)) : '';

        return "{$reported}, {$difference} {$word} than in {$other} ({$previous}){$trailer}.";
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function trend(array $facts): ?string
    {
        /** @var list<ReportBucket> $buckets */
        $buckets = $facts['buckets'];

        if (count($buckets) < 2) {
            return null;
        }

        $complete = array_values(array_filter($buckets, fn (ReportBucket $b): bool => $b->isComplete()));
        $total = array_sum(array_map(fn (ReportBucket $b): int => (int) ($facts['counts'][$b->key] ?? 0), $complete));
        /** @var ReportGranularity $unit */
        $unit = $facts['unit'];

        if (count($complete) < 6 || $total < 10) {
            return sprintf(
                'Too few tickets (%d) across %d complete %s to describe a trend.',
                $total,
                count($complete),
                $unit->noun(count($complete)),
            );
        }

        $k = intdiv(count($complete), 3);
        $rate = fn (array $slice): float => array_sum(array_map(fn (ReportBucket $b): float => ((int) ($facts['counts'][$b->key] ?? 0)) / $b->days(), $slice)) / count($slice);
        $first = $rate(array_slice($complete, 0, $k));
        $last = $rate(array_slice($complete, -$k));
        $sum = fn (array $slice): int => array_sum(array_map(fn (ReportBucket $b): int => (int) ($facts['counts'][$b->key] ?? 0), $slice));
        $moved = abs($sum(array_slice($complete, -$k)) - $sum(array_slice($complete, 0, $k)));

        $units = sprintf('%d %s', $k, $unit->noun($k));
        $firstText = number_format($first, 1);
        $lastText = number_format($last, 1);

        if ($last >= 1.5 * $first && $last > $first && $moved >= 3) {
            return "Reports rose through the period: {$lastText} a day over the last {$units}, against {$firstText} a day over the first {$k}.";
        }

        if ($first >= 1.5 * $last && $first > $last && $moved >= 3) {
            return "Reports fell through the period: {$lastText} a day over the last {$units}, against {$firstText} a day over the first {$k}.";
        }

        return "No steady rise or fall: {$firstText} a day over the first {$units} and {$lastText} over the last {$k}.";
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function peak(array $facts): ?string
    {
        /** @var list<ReportBucket> $buckets */
        $buckets = $facts['buckets'];
        $complete = array_values(array_filter($buckets, fn (ReportBucket $b): bool => $b->isComplete()));

        if (count($buckets) < 2 || $complete === []) {
            return null;
        }

        $max = max(array_map(fn (ReportBucket $b): int => (int) ($facts['counts'][$b->key] ?? 0), $complete));

        if ($max === 0) {
            return null;
        }

        $top = array_values(array_filter($complete, fn (ReportBucket $b): bool => (int) ($facts['counts'][$b->key] ?? 0) === $max));
        /** @var ReportGranularity $unit */
        $unit = $facts['unit'];

        if (count($top) === 1) {
            return sprintf('Busiest %s: %s, with %s.', $unit->noun(), $top[0]->label, self::tickets($max));
        }

        return sprintf('%d %s tied as busiest, with %s each.', count($top), $unit->noun(count($top)), self::tickets($max));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, string>
     */
    private static function locations(array $facts): array
    {
        $locations = $facts['locations'];

        if ($locations === null) {
            return [];
        }

        $sentences = [];
        $top = $locations['top'];

        if ($top !== [] && $locations['located'] > 0) {
            $count = $top[0]['count'];
            $sentences['location_top'] = count($top) === 1
                ? sprintf('%s had the most problems: %d (%s%% of problems with a recorded room).', $top[0]['label'], $count, ReportMetrics::share($count, $locations['located']))
                : (count($top) === 2
                    ? sprintf('%s and %s tied for the most problems, with %d each.', $top[0]['label'], $top[1]['label'], $count)
                    : sprintf('%d rooms tied for the most problems, with %d each.', count($top), $count));
        }

        $types = $locations['types'];

        if ($locations['all_scope'] && isset($types['laboratory'], $types['office'])
            && ($types['laboratory']['count'] > 0 || $types['office']['count'] > 0)) {
            $sentences['location_types'] = sprintf(
                'Laboratories: %d %s%s; offices: %d %s%s.',
                $types['laboratory']['count'],
                $types['laboratory']['count'] === 1 ? 'problem' : 'problems',
                $types['laboratory']['per_room'] !== null ? " ({$types['laboratory']['per_room']} a room)" : '',
                $types['office']['count'],
                $types['office']['count'] === 1 ? 'problem' : 'problems',
                $types['office']['per_room'] !== null ? " ({$types['office']['per_room']} a room)" : '',
            );
        }

        if ($locations['unrecorded'] > 0) {
            $sentences['unrecorded'] = sprintf('%s no room recorded.', ucfirst(self::plural($locations['unrecorded'], 'problem had', 'problems had')));
        }

        return $sentences;
    }

    private static function tickets(int $count): string
    {
        return self::plural($count, 'ticket');
    }

    private static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
