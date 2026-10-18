<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The periods a report can cover (Phase 2.10). How each one resolves to a
 * school-local, half-open range lives in `ReportPeriod::resolve()`.
 */
enum ReportPeriodPreset: string
{
    use HasValues;

    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case Custom = 'custom';
    case SinceYear = 'since_year';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Year => 'Year',
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::Last90Days => 'Last 90 days',
            self::Custom => 'Custom range',
            self::SinceYear => 'Since a year',
        };
    }

    /** A calendar unit picked by an anchor date (and stepped by ‹ ›). */
    public function isCalendar(): bool
    {
        return in_array($this, [self::Day, self::Week, self::Month, self::Year], true);
    }

    /** Trailing days ending today, or null when the preset is not rolling. */
    public function rollingDays(): ?int
    {
        return match ($this) {
            self::Last7Days => 7,
            self::Last30Days => 30,
            self::Last90Days => 90,
            default => null,
        };
    }
}
