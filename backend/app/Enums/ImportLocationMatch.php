<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How a location text matched the estate (Phase 2.10 R6, SDD DD-97). Only
 * `matched` is applied without a decision: an exact room code with nothing
 * contradicting it, or an exact building + floor + room. Suggestions are never
 * applied on their own.
 */
enum ImportLocationMatch: string
{
    use HasValues;

    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case NotSelectable = 'not_selectable';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched',
            self::Ambiguous => 'Choose a room',
            self::Unmatched => 'No match',
            self::NotSelectable => 'Room not in use',
        };
    }
}
