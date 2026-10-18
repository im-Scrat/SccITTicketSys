<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * One recipient's copy of an emailed report (Phase 2.10, SDD DD-92).
 *
 * `sent` means the mail server accepted the message — never that it was
 * delivered or read, which this application cannot know. The interface says
 * "Sent to mail server" for exactly that reason.
 */
enum ReportEmailDeliveryStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent to mail server',
            self::Failed => 'Failed',
        };
    }
}
