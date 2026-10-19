<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How a catalog combination (category, brand, model) matched the catalog
 * (Phase 2.10 R6, owner decision D5). `blocked` is a collision with an archived
 * entry: it must not be silently recreated.
 */
enum ImportCatalogMatch: string
{
    use HasValues;

    case Existing = 'existing';
    case New = 'new';
    case Suggested = 'suggested';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Existing => 'In the catalog',
            self::New => 'New catalog entry',
            self::Suggested => 'Possible match',
            self::Blocked => 'Archived in the catalog',
        };
    }
}
