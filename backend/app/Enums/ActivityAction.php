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
    case RegistrationUpdated = 'registration_updated';
    case AccountActivated = 'account_activated';
    case AccountSuspended = 'account_suspended';
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case AccountLocked = 'account_locked';

    // Phase 2.3 — User Management administrative actions (SRS v1.2 FR-USER).
    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserReactivated = 'user_reactivated';
    case AccountDeactivated = 'account_deactivated';
    case UserArchived = 'user_archived';
    case UserRestored = 'user_restored';
    case RoleChanged = 'role_changed';
    case PermissionsChanged = 'permissions_changed';
    case ForcePasswordResetSet = 'force_password_reset_set';
    case AccountUnlocked = 'account_unlocked';
    case ApprovalEmailResent = 'approval_email_resent';
    case RejectionEmailResent = 'rejection_email_resent';
    case PasswordResetEmailResent = 'password_reset_email_resent';
    case UsersExported = 'users_exported';
    case BulkAction = 'bulk_action';

    /** Human-readable label for audit-timeline rendering. */
    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::RegistrationApproved => 'Registration approved',
            self::RegistrationRejected => 'Registration rejected',
            self::RegistrationUpdated => 'Registration updated',
            self::AccountActivated => 'Account activated',
            self::AccountSuspended => 'Account suspended',
            self::PasswordChanged => 'Password changed',
            self::PasswordResetRequested => 'Password reset requested',
            self::PasswordResetCompleted => 'Password reset completed',
            self::AccountLocked => 'Account locked',
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::UserReactivated => 'Account reactivated',
            self::AccountDeactivated => 'Account deactivated',
            self::UserArchived => 'Account archived',
            self::UserRestored => 'Account restored',
            self::RoleChanged => 'Role changed',
            self::PermissionsChanged => 'Permissions changed',
            self::ForcePasswordResetSet => 'Password reset required',
            self::AccountUnlocked => 'Account unlocked',
            self::ApprovalEmailResent => 'Approval email resent',
            self::RejectionEmailResent => 'Rejection email resent',
            self::PasswordResetEmailResent => 'Password reset email resent',
            self::UsersExported => 'Users exported',
            self::BulkAction => 'Bulk action',
        };
    }
}
