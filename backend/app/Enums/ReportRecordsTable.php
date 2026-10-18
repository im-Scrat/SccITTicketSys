<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The server-paginated record tables of the Overall Reports page (Phase 2.10
 * R4, SDD DD-94), and the section each belongs to.
 */
enum ReportRecordsTable: string
{
    use HasValues;

    case Tickets = 'tickets';
    case Repairs = 'repairs';
    case Replacements = 'replacements';
    case PcUnits = 'pc_units';
    case Assets = 'assets';
    case Rooms = 'rooms';

    public function label(): string
    {
        return match ($this) {
            self::Tickets => 'Ticket records',
            self::Repairs => 'Repair records',
            self::Replacements => 'Replacement records',
            self::PcUnits => 'PC units',
            self::Assets => 'Other equipment',
            self::Rooms => 'Rooms',
        };
    }

    public function section(): OverallReportSection
    {
        return match ($this) {
            self::Tickets => OverallReportSection::Tickets,
            self::Repairs, self::Replacements => OverallReportSection::Repairs,
            self::PcUnits, self::Assets => OverallReportSection::Equipment,
            self::Rooms => OverallReportSection::Locations,
        };
    }
}
