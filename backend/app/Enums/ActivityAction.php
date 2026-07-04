<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Actor-initiated activity events recorded in `activity_logs` (SRS FR-AUD-*,
 * NFR-SEC-017). This is the reusable vocabulary the AuditLogger writes; future
 * modules extend it with their own cases. Authentication attempts themselves
 * (login/logout/failed/locked_out) live in `login_history` via LoginStatus.
 *
 * `activity_logs.action` is a free-form varchar, so these values are an
 * application-side convention (typed for maintainability), not a DB CHECK.
 */
enum ActivityAction: string
{
    use HasValues;

    case Registered = 'registered';
    case RegistrationApproved = 'registration_approved';
    case RegistrationRejected = 'registration_rejected';
    case AccountActivated = 'account_activated';
    case AccountSuspended = 'account_suspended';
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case AccountLocked = 'account_locked';
}
