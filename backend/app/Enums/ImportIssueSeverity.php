<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How much an issue blocks a row (Phase 2.10 R6, Appendix B.5): an error
 * makes it invalid, a duplicate skips it, a review issue needs a decision or
 * an acknowledgement, a warning saves as shown, info is shown only.
 */
enum ImportIssueSeverity: string
{
    use HasValues;

    case Error = 'error';
    case Duplicate = 'duplicate';
    case Review = 'review';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'Error',
            self::Duplicate => 'Duplicate',
            self::Review => 'Needs a decision',
            self::Warning => 'Warning',
            self::Info => 'Information',
        };
    }
}
