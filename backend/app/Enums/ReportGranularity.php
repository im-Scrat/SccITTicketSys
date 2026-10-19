<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How a report's time series is grouped (Phase 2.10).
 *
 * There is deliberately no hour unit: with the school's volumes an hourly line
 * is a row of zeros, and a one-day period is shown as a figure, not a chart.
 * `auto` picks the unit from the period's length (`ReportPeriod::resolveGranularity()`).
 */
enum ReportGranularity: string
{
    use HasValues;

    case Auto = 'auto';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Year => 'Year',
        };
    }

    /**
     * The most buckets one series may hold in this unit. A chart beyond it is
     * unreadable at 16px ticks, so an explicit choice past the cap is refused
     * with a message that names the limit.
     */
    public function maxBuckets(): int
    {
        return match ($this) {
            self::Day => 92,
            self::Week => 105,
            self::Month => 120,
            self::Year, self::Auto => 50,
        };
    }

    /** The unit in running text: "day", "week", … (plural when $count != 1). */
    public function noun(int $count = 1): string
    {
        $noun = match ($this) {
            self::Day, self::Auto => 'day',
            self::Week => 'week',
            self::Month => 'month',
            self::Year => 'year',
        };

        return $count === 1 ? $noun : $noun.'s';
    }
}
