<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The administrator's decision on a catalog proposal (Phase 2.10 R6, D5).
 * Approved entries are created only after the import is confirmed.
 */
enum ImportCatalogDecision: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Existing = 'existing';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not decided',
            self::Approved => 'Approved',
            self::Existing => 'Use an existing model',
            self::Rejected => "Don't import these rows",
        };
    }
}
