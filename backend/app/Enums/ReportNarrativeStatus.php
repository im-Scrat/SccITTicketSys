<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Where an AI interpretation of a report is (Phase 2.10 R5, SDD DD-95).
 * `insufficient` is decided by the application before any provider call —
 * no question had enough recorded data to interpret.
 */
enum ReportNarrativeStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Completed = 'completed';
    case Insufficient = 'insufficient';
    case Unavailable = 'unavailable';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Generating…',
            self::Completed => 'Up to date',
            self::Insufficient => 'Not enough data',
            self::Unavailable => 'Not configured',
            self::Failed => 'Failed',
            self::Disabled => 'Off',
        };
    }
}
