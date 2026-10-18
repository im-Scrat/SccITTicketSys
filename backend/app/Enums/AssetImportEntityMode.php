<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * What a file holds (Phase 2.10 R6, SRS FR-AST-016): PC units, other equipment,
 * or both — in which case each row's category decides which register it joins.
 */
enum AssetImportEntityMode: string
{
    use HasValues;

    case PcUnits = 'pc_units';
    case Assets = 'assets';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::PcUnits => 'PC units',
            self::Assets => 'Other equipment',
            self::Mixed => 'Both in one file',
        };
    }
}
