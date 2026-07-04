<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Account lifecycle status (SRS DR-015, FR-AUTH-004). Only `Active` accounts
 * may authenticate; every other status is refused by the account-status
 * middleware (SDD DD-19). `Rejected` is the terminal state of a declined
 * registration request (SDD DD-18).
 */
enum UserStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    /** Whether an account in this status is permitted to authenticate. */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Active => 'Active',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
            self::Inactive => 'Inactive',
        };
    }
}
