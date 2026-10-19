<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How the file writes dates whose day and month are both 12 or less
 * (Phase 2.10 R6, Appendix B.4). Chosen by the administrator, never guessed.
 */
enum ImportDateFormat: string
{
    use HasValues;

    case DayFirst = 'dmy';
    case MonthFirst = 'mdy';

    public function label(): string
    {
        return match ($this) {
            self::DayFirst => 'Day first (31/01/2024)',
            self::MonthFirst => 'Month first (01/31/2024)',
        };
    }
}
