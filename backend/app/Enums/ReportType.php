<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The reports a document can be built from (Phase 2.10, SDD DD-91). The CHECK
 * on `report_exports.report_type` is frozen in its migration; a schema test
 * keeps it equal to this list.
 */
enum ReportType: string
{
    use HasValues;

    case TicketOverview = 'ticket_overview';
    case Overall = 'overall';

    public function label(): string
    {
        return match ($this) {
            self::TicketOverview => 'Ticket overview',
            self::Overall => 'Overall report',
        };
    }

    /** The ASCII stem of a downloaded file's name. */
    public function fileStem(): string
    {
        return match ($this) {
            self::TicketOverview => 'ticket-overview',
            self::Overall => 'overall-report',
        };
    }
}
