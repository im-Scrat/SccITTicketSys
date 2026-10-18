<?php

declare(strict_types=1);

use App\Domains\Analytics\DTOs\ReportPeriod;
use App\Domains\Analytics\Exceptions\ReportRangeException;
use App\Enums\ReportComparison;
use App\Enums\ReportGranularity;
use App\Enums\ReportPeriodPreset;
use App\Support\SchoolClock;
use Tests\Support\ReportFixtures as F;

/*
 * Phase 2.10 — the report time engine (SDD DD-86, SRS FR-RPT-002).
 *
 * Every rule here is one a report would otherwise get subtly wrong: the school's
 * day, not UTC's; Monday weeks; "to date" periods that end now rather than at
 * midnight tonight; comparisons that line up weekday with weekday; and buckets
 * that say when they are partial, still running, or before records began.
 */

it('resolves "this month" to the school-local month, running to the next whole minute', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Month, null, null, null, null, F::local('2026-10-18 04:41:00'));

    expect($p->start->format('Y-m-d H:i'))->toBe('2026-10-01 00:00')
        ->and($p->end->format('Y-m-d H:i'))->toBe('2026-10-18 04:41')
        ->and($p->naturalEnd->format('Y-m-d'))->toBe('2026-11-01')
        ->and($p->toDate())->toBeTrue()
        ->and($p->days())->toBe(18)
        ->and($p->rangeLabel())->toBe('1–18 Oct 2026 (to date)')
        ->and($p->label())->toBe('October 2026');
});

it('starts a school day at local midnight, which is 16:00 UTC the day before', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Day, '2026-10-18', null, null, null, F::local('2026-10-19 09:00'));

    expect($p->startUtc()->toIso8601ZuluString())->toBe('2026-10-17T16:00:00Z')
        ->and($p->endUtc()->toIso8601ZuluString())->toBe('2026-10-18T16:00:00Z')
        ->and($p->toDate())->toBeFalse()
        ->and($p->days())->toBe(1);
});

it('starts weeks on Monday (ISO), whatever day the anchor is', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Week, '2026-10-18', null, null, null, F::local('2026-10-25 10:00'));

    expect($p->start->format('D Y-m-d'))->toBe('Mon 2026-10-12')
        ->and($p->naturalEnd->format('D Y-m-d'))->toBe('Mon 2026-10-19');
});

it('compares "this month to date" with the previous month to the same point in time', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Month, null, null, null, null, F::local('2026-10-18 04:41:00'));
    $c = $p->comparison(ReportComparison::Previous, ReportGranularity::Day);

    expect($c)->not->toBeNull()
        ->and($c->start->format('Y-m-d H:i'))->toBe('2026-09-01 00:00')
        ->and($c->end->format('Y-m-d H:i'))->toBe('2026-09-18 04:41')
        ->and($c->expressibleAsDates())->toBeFalse()
        ->and($p->expressibleAsDates())->toBeTrue();
});

it('clips the previous window to the end of a shorter month', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Month, null, null, null, null, F::local('2026-03-31 10:00'));
    $c = $p->comparison(ReportComparison::Previous, ReportGranularity::Day);

    expect($c->start->format('Y-m-d'))->toBe('2026-02-01')
        ->and($c->end->format('Y-m-d H:i'))->toBe('2026-02-28 10:00');
});

it('compares a whole past month with the whole month before it', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Month, '2026-09-10', null, null, null, F::local('2026-10-18 04:41'));
    $c = $p->comparison(ReportComparison::Previous, ReportGranularity::Day);

    expect($p->toDate())->toBeFalse()
        ->and($c->start->format('Y-m-d'))->toBe('2026-08-01')
        ->and($c->end->format('Y-m-d'))->toBe('2026-09-01')
        ->and($c->expressibleAsDates())->toBeTrue();
});

it('shifts days and weeks by 52 weeks for "same period last year", so weekdays line up', function () {
    $week = ReportPeriod::resolve(ReportPeriodPreset::Week, '2026-10-14', null, null, null, F::local('2026-10-25 10:00'));
    $c = $week->comparison(ReportComparison::LastYear, ReportGranularity::Day);

    expect($c->start->format('D Y-m-d'))->toBe('Mon 2025-10-13')
        ->and($c->shift)->toBe('weeks_52')
        ->and($c->isoWeekLabel())->toBe('ISO week 42, 2025')
        ->and($week->start->isoWeek())->toBe(42);
});

it('shifts months and years by the calendar year, turning 29 February into the 28th', function () {
    $leap = ReportPeriod::resolve(ReportPeriodPreset::Day, '2028-02-29', null, null, null, F::local('2028-03-05 10:00'));
    $month = ReportPeriod::resolve(ReportPeriodPreset::Month, '2026-10-01', null, null, null, F::local('2026-10-18 10:00'));

    expect($month->comparison(ReportComparison::LastYear, ReportGranularity::Day)->start->format('Y-m-d'))->toBe('2025-10-01')
        ->and($month->comparison(ReportComparison::LastYear, ReportGranularity::Day)->shift)->toBe('calendar_year')
        // A single day uses the 52-week rule as well: the same weekday a year back.
        ->and($leap->comparison(ReportComparison::LastYear, ReportGranularity::Day)->start->format('D Y-m-d'))->toBe('Tue 2027-03-02');
});

it('uses the 52-week shift for any weekly grouping, even of a longer period', function () {
    $quarter = ReportPeriod::resolve(ReportPeriodPreset::Last90Days, null, null, null, null, F::local('2026-10-18 10:00'));

    expect($quarter->comparison(ReportComparison::LastYear, ReportGranularity::Week)->shift)->toBe('weeks_52')
        ->and($quarter->comparison(ReportComparison::LastYear, ReportGranularity::Month)->shift)->toBe('calendar_year');
});

it('shifts a rolling period back by its own length', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Last30Days, null, null, null, null, F::local('2026-10-18 10:00'));
    $c = $p->comparison(ReportComparison::Previous, ReportGranularity::Day);

    expect($p->start->format('Y-m-d'))->toBe('2026-09-19')
        ->and($c->start->format('Y-m-d'))->toBe('2026-08-20')
        ->and($c->naturalEnd->format('Y-m-d'))->toBe('2026-09-19')
        ->and($c->end->lessThan($p->start))->toBeTrue();
});

it('never compares "since a year"', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::SinceYear, null, null, null, 2025, F::local('2026-10-18 10:00'));

    expect($p->comparison(ReportComparison::Previous, ReportGranularity::Month))->toBeNull()
        ->and($p->label())->toBe('Since 2025')
        ->and($p->start->format('Y-m-d'))->toBe('2025-01-01');
});

it('picks the grouping from the period length when asked for automatic', function (ReportPeriodPreset $preset, ?int $sinceYear, ReportGranularity $expected) {
    $p = ReportPeriod::resolve($preset, null, null, null, $sinceYear, F::local('2026-10-18 10:00'));

    expect($p->resolveGranularity(ReportGranularity::Auto))->toBe($expected);
})->with([
    'a day by days' => [ReportPeriodPreset::Day, null, ReportGranularity::Day],
    'a month by days' => [ReportPeriodPreset::Month, null, ReportGranularity::Day],
    '90 days by weeks' => [ReportPeriodPreset::Last90Days, null, ReportGranularity::Week],
    'a year by months' => [ReportPeriodPreset::Year, null, ReportGranularity::Month],
    'since 2000 by years' => [ReportPeriodPreset::SinceYear, 2000, ReportGranularity::Year],
]);

it('refuses a grouping that would need more buckets than its cap, naming the limit', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Custom, null, '2025-01-01', '2025-12-31', null, F::local('2026-10-18 10:00'));

    expect(fn () => $p->resolveGranularity(ReportGranularity::Day))
        ->toThrow(ReportRangeException::class, 'Group by day allows at most 92 days');
});

it('flags partial, in-progress and before-records buckets', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Month, null, null, null, null, F::local('2026-10-18 04:41'));
    $buckets = $p->buckets(ReportGranularity::Week, F::local('2026-10-09 08:00'));

    // Oct 2026 starts on a Thursday: the first week is clipped to Thu–Sun.
    expect($buckets[0]->key)->toBe('2026-09-28')
        ->and($buckets[0]->label)->toBe('1–4 Oct 2026')
        ->and($buckets[0]->partial)->toBeTrue()
        ->and($buckets[0]->beforeRecords)->toBeTrue()
        ->and($buckets[0]->toArray(0)['count'])->toBeNull()
        ->and($buckets[1]->beforeRecords)->toBeFalse()
        ->and($buckets[1]->isComplete())->toBeTrue()
        ->and(end($buckets)->inProgress)->toBeTrue()
        ->and(end($buckets)->key)->toBe('2026-10-12')
        ->and(count($buckets))->toBe(3);
});

it('leaves future buckets out instead of drawing them as zero', function () {
    $p = ReportPeriod::resolve(ReportPeriodPreset::Month, null, null, null, null, F::local('2026-10-18 04:41'));

    expect(count($p->buckets(ReportGranularity::Day, null)))->toBe(18);
});

it('writes ranges compactly, across months and years', function () {
    expect(ReportPeriod::rangeText(F::local('2026-10-18'), F::local('2026-10-19')))->toBe('18 Oct 2026')
        ->and(ReportPeriod::rangeText(F::local('2026-09-29'), F::local('2026-10-06')))->toBe('29 Sep–5 Oct 2026')
        ->and(ReportPeriod::rangeText(F::local('2025-12-29'), F::local('2026-01-05')))->toBe('29 Dec 2025–4 Jan 2026');
});

it('refuses an impossible calendar date instead of rolling it over', function () {
    expect(fn () => SchoolClock::startOfLocalDay('2026-02-31'))->toThrow(InvalidArgumentException::class);
});
