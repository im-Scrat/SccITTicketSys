<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * What the save did with a row (Phase 2.10 R6). `skipped_duplicate` includes a
 * duplicate first noticed at save time (`duplicate_at_save`): the existing
 * record is left exactly as it was.
 */
enum AssetImportRowOutcome: string
{
    use HasValues;

    case Created = 'created';
    case SkippedDuplicate = 'skipped_duplicate';
    case Failed = 'failed';
    case NotAttempted = 'not_attempted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::SkippedDuplicate => 'Skipped: already exists',
            self::Failed => 'Failed',
            self::NotAttempted => 'Not attempted',
        };
    }
}
