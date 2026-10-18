<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\ImportDateFormat;
use DateTimeImmutable;

/**
 * Strict date reading for imported cells (Phase 2.10 R6, plan Appendix B.4).
 *
 * There is no free-form `strtotime()`: "31/02/2024" must be refused, not
 * quietly rolled into March, and "1/2/2024" must never be read in whichever
 * order a library prefers. A value is accepted only in one of the listed
 * shapes and only if it names a real calendar date:
 *
 *  - a date-formatted Excel cell (already a date when read);
 *  - `Y-m-d`, `Y/m/d`; `d/m/Y` or `m/d/Y` per the administrator's choice
 *    (also with `-` or `.`); `j M Y`, `M j Y`, `F j Y`, `d-M-Y`;
 *  - an Excel serial number within a plausible window (1955–2099) — which is
 *    always surfaced for review, because a bare number in a date column is as
 *    likely to be a typo as a date.
 *
 * Year-only values are refused: a purchase year is not a purchase date.
 */
final class ImportDateParser
{
    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
        'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9,
        'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
    ];

    /** Excel serials for 1955-01-01 and 2099-12-31. */
    private const SERIAL_MIN = 20090;

    private const SERIAL_MAX = 73050;

    /**
     * @param  list<string>  $flags
     * @return array{value: string|null, issue: string|null, message: string|null}
     */
    public static function parse(string $raw, array $flags, ?ImportDateFormat $order): array
    {
        $raw = trim($raw);
        $ok = fn (int $y, int $m, int $d): array => checkdate($m, $d, $y) && $y >= 1950 && $y <= 2100
            ? ['value' => sprintf('%04d-%02d-%02d', $y, $m, $d), 'issue' => null, 'message' => null]
            : ['value' => null, 'issue' => 'date_unparseable', 'message' => "{$raw} is not a real date."];

        if (in_array('date_cell', $flags, true) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return $ok((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^\d{4}$/', $raw)) {
            return ['value' => null, 'issue' => 'date_unparseable', 'message' => "{$raw} is a year, not a date. Enter the full date."];
        }

        if (preg_match('/^\d+(\.0+)?$/', $raw)) {
            $serial = (int) $raw;
            if ($serial >= self::SERIAL_MIN && $serial <= self::SERIAL_MAX) {
                $date = (new DateTimeImmutable('1899-12-30'))->modify("+{$serial} days");

                return ['value' => $date->format('Y-m-d'), 'issue' => 'date_from_serial', 'message' => "{$raw} was read as an Excel date, {$date->format('j M Y')}. Check it is right."];
            }

            return self::unparseable($raw);
        }

        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $raw, $m)) {
            return $ok((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($order === null) {
                if ($a <= 12 && $b <= 12 && $a !== $b) {
                    return ['value' => null, 'issue' => 'date_ambiguous', 'message' => "{$raw} could be read day-first or month-first. Choose how this file writes dates."];
                }

                // Unambiguous on its own: a part above 12 can only be the day.
                return $a > 12 ? $ok($y, $b, $a) : $ok($y, $a, $b);
            }

            return $order === ImportDateFormat::DayFirst ? $ok($y, $b, $a) : $ok($y, $a, $b);
        }

        $lower = mb_strtolower($raw);
        // 5 Jan 2024 · 05-Jan-2024 · 5 January 2024
        if (preg_match('/^(\d{1,2})[\s\-]+([a-z]+)\.?[\s\-,]+(\d{4})$/', $lower, $m) && isset(self::MONTHS[$m[2]])) {
            return $ok((int) $m[3], self::MONTHS[$m[2]], (int) $m[1]);
        }
        // Jan 5 2024 · January 5, 2024
        if (preg_match('/^([a-z]+)\.?\s+(\d{1,2}),?\s+(\d{4})$/', $lower, $m) && isset(self::MONTHS[$m[1]])) {
            return $ok((int) $m[3], self::MONTHS[$m[1]], (int) $m[2]);
        }

        return self::unparseable($raw);
    }

    /**
     * Whether a raw text date could be read either way — the mapping step asks
     * for a date order only when the file holds one of these.
     */
    public static function isAmbiguous(string $raw): bool
    {
        return (bool) preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.]\d{4}$/', trim($raw), $m)
            && (int) $m[1] <= 12 && (int) $m[2] <= 12 && $m[1] !== $m[2];
    }

    /**
     * Which order a raw date proves, if any (a part above 12 is the day).
     */
    public static function evidence(string $raw): ?ImportDateFormat
    {
        if (! preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.]\d{4}$/', trim($raw), $m)) {
            return null;
        }

        return match (true) {
            (int) $m[1] > 12 && (int) $m[2] <= 12 => ImportDateFormat::DayFirst,
            (int) $m[2] > 12 && (int) $m[1] <= 12 => ImportDateFormat::MonthFirst,
            default => null,
        };
    }

    /**
     * @return array{value: null, issue: string, message: string}
     */
    private static function unparseable(string $raw): array
    {
        return ['value' => null, 'issue' => 'date_unparseable', 'message' => "{$raw} is not a date the import can read. Enter it as YYYY-MM-DD, for example 2024-03-31."];
    }
}
