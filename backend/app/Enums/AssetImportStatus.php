<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Where an import is (Phase 2.10 R6, SDD DD-96). `reading` → `mapping` →
 * `validating` → `review` are the draft stages, editable and cancellable;
 * `queued` → `importing` is the save; `finished` carries an outcome. A draft
 * left alone expires; a file that cannot be read is `unreadable`.
 */
enum AssetImportStatus: string
{
    use HasValues;

    case Reading = 'reading';
    case Mapping = 'mapping';
    case Validating = 'validating';
    case Review = 'review';
    case Queued = 'queued';
    case Importing = 'importing';
    case Finished = 'finished';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Unreadable = 'unreadable';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'Reading the file',
            self::Mapping => 'Map columns',
            self::Validating => 'Checking rows',
            self::Review => 'Review and fix',
            self::Queued => 'Waiting to start',
            self::Importing => 'Importing',
            self::Finished => 'Finished',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Unreadable => 'Could not be read',
        };
    }

    /** The administrator may still change the mapping, rows and decisions. */
    public function isDraft(): bool
    {
        return in_array($this, [self::Reading, self::Mapping, self::Validating, self::Review, self::Unreadable], true);
    }

    /** A save is under way (or about to be). */
    public function isRunning(): bool
    {
        return $this === self::Queued || $this === self::Importing;
    }

    /** Nothing further can happen to it, short of a retry of a finished import. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Finished, self::Cancelled, self::Expired], true);
    }
}
