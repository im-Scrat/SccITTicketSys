<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Where a queued report document is (Phase 2.10, SDD DD-91). `pending` →
 * `processing` is a claim made under a conditional update, so two workers can
 * never render the same row; `expired` keeps the row (and its audit trail)
 * after the file has been deleted.
 */
enum ReportExportStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Processing => 'Preparing',
            self::Completed => 'Ready',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
