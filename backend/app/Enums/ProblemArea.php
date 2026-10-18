<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The problem area a ticket category belongs to (Phase 2.10, owner decision
 * D6): the classification the reports group faults by.
 *
 * Stored on `ticket_categories.problem_area` (varchar + CHECK, the project's
 * enum convention), so it is data an administrator's categories carry rather
 * than a slug list in code. The mapping of the 16 categories that existed on
 * 2026-10-17 was approved by the owner on 2026-10-18 and is frozen in
 * `2026_10_18_100000_add_problem_area_to_ticket_categories`.
 */
enum ProblemArea: string
{
    use HasValues;

    case Hardware = 'hardware';
    case Software = 'software';
    case Network = 'network';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hardware => 'Hardware',
            self::Software => 'Software',
            self::Network => 'Network',
            self::Other => 'Other',
        };
    }
}
