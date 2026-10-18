<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The one server-side verdict on a finished import (Phase 2.10 R6, owner
 * decision D3, SDD DD-96). Computed once by the finalizer and shown in the same
 * words everywhere; `partial` is any deviation from the confirmed plan and is
 * never labelled complete.
 */
enum AssetImportOutcome: string
{
    use HasValues;

    case Complete = 'complete';
    case Partial = 'partial';
    case NothingNew = 'nothing_new';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Import complete',
            self::Partial => 'Partially imported',
            self::NothingNew => 'No new records',
            self::Failed => 'Nothing imported',
            self::Stopped => 'Import stopped',
        };
    }
}
