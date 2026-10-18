<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Why a report document was produced (Phase 2.10, SDD DD-92): to download, or
 * to attach to an email. An emailed document always uses the identifier-only
 * profile — a CHECK enforces it in the database as well as here.
 */
enum ReportExportPurpose: string
{
    use HasValues;

    case Download = 'download';
    case Email = 'email';
}
