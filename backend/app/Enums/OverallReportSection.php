<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The sections of the Overall Reports page (Phase 2.10 R4, SDD DD-94). The AI
 * interpretation section arrives in R5 and is not a figures section.
 */
enum OverallReportSection: string
{
    use HasValues;

    case Overview = 'overview';
    case Tickets = 'tickets';
    case Equipment = 'equipment';
    case Repairs = 'repairs';
    case Locations = 'locations';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Tickets => 'Tickets',
            self::Equipment => 'Equipment',
            self::Repairs => 'Repairs & maintenance',
            self::Locations => 'Locations',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::Overview => 'The period at a glance: tickets, repairs, replacements and the places and equipment with the most problems.',
            self::Tickets => 'What was reported, how it moved, and how quickly staff responded and resolved it.',
            self::Equipment => 'The equipment register as of today, what was added in the period, and the equipment with the most problems.',
            self::Repairs => 'Completed repairs and maintenance, replaced parts, recurring patterns and equipment due for preventive care.',
            self::Locations => 'Where problems and repairs happened, by room, floor or building.',
        };
    }
}
