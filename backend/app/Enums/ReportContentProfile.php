<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How much of each record a report document may carry (Phase 2.10, SDD DD-92).
 *
 * - **full**: what the administrator sees on screen, ticket titles included.
 *   Downloads and print only — the file goes to the person who asked for it.
 * - **identifiers**: identifiers and classifications only — ticket number,
 *   category, status, room code, dates, repair type, PC code. **No titles,
 *   descriptions or notes**, which are free text a reporter typed and may name
 *   people. Every emailed attachment uses it, whoever the recipient is, because
 *   an email leaves the system the moment it is sent.
 */
enum ReportContentProfile: string
{
    use HasValues;

    case Full = 'full';
    case Identifiers = 'identifiers';
}
