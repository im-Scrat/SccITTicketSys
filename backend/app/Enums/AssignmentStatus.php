<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum AssignmentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Declined = 'declined';
    case Reassigned = 'reassigned';
    case Cancelled = 'cancelled';

    /**
     * Statuses that count as an "active" assignment (one allowed per ticket).
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [
            self::Pending->value,
            self::Accepted->value,
            self::InProgress->value,
            self::OnHold->value,
        ];
    }
}
