<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * What a report's period is compared with (Phase 2.10). The window arithmetic
 * lives in `ReportPeriod::comparison()`.
 */
enum ReportComparison: string
{
    use HasValues;

    case None = 'none';
    case Previous = 'previous';
    case LastYear = 'last_year';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No comparison',
            self::Previous => 'Previous period',
            self::LastYear => 'Same period last year',
        };
    }
}
