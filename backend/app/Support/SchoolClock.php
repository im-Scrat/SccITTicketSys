<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The school's clock: which zone "today", "this week" and "this month" mean.
 *
 * The application stores and computes in UTC (`app.timezone`), and keeps doing
 * so. Only the *business* meaning of a day is school-local, and SRS FR-RPT-002
 * requires report buckets to be timezone-correct: a ticket filed at 07:30 on
 * Monday in Manila is a Monday ticket, although in UTC it was filed on Sunday.
 *
 * This is the one authority for that zone (Phase 2.10, SDD DD-86). It is a
 * constant rather than a settings lookup for the reason DailyDigest gave first:
 * console routes and schedules load on every artisan call, where a query would
 * run during `migrate` and fail during `migrate:fresh`. DailyDigestTest pins it
 * to the seeded `system.timezone`, so the two cannot silently diverge.
 *
 * Every "today" in reporting code comes from here, and is passed into SQL as a
 * bound instant. SQL never calls `now()`: a query and the PHP that labels its
 * result must agree on the instant, to the second.
 */
final class SchoolClock
{
    public const TIMEZONE = 'Asia/Manila';

    /** Now, in the school's zone. Honours `Carbon::setTestNow()`. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    /** The start of today, in the school's zone. */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * Midnight at the start of a school-local date.
     *
     * Strict: `2026-02-31` is refused rather than rolled into March, so a
     * mistyped filter is an error instead of a quietly different range.
     */
    public static function startOfLocalDay(string $ymd): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $ymd, self::TIMEZONE);

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $ymd) {
            throw new InvalidArgumentException("\"{$ymd}\" is not a calendar date.");
        }

        return $day;
    }

    /**
     * The instant a "to date" report runs up to: the start of the next whole
     * minute. Rounding up keeps a report built a few seconds ago and one built
     * now on the same key, and nothing can be recorded in the future, so no
     * row falls between the boundary and the real now.
     */
    public static function asOf(): CarbonImmutable
    {
        return self::now()->startOfMinute()->addMinute();
    }
}
