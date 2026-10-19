<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Which register a row joins (Phase 2.10 R6).
 */
enum ImportEntityType: string
{
    use HasValues;

    case PcUnit = 'pc_unit';
    case Asset = 'asset';

    public function label(): string
    {
        return match ($this) {
            self::PcUnit => 'PC unit',
            self::Asset => 'Asset',
        };
    }
}
