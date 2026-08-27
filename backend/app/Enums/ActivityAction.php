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

    // Phase 2.4 — Location Management (SRS FR-LOC). One compact vocabulary for
    // buildings, floors and rooms: the activity_logs subject morph identifies
    // which entity the event is about, so the timeline renders e.g.
    // "Building created" from the subject type + this label.
    case LocationCreated = 'location_created';
    case LocationUpdated = 'location_updated';
    case LocationActivated = 'location_activated';
    case LocationDeactivated = 'location_deactivated';
    case LocationArchived = 'location_archived';
    case LocationRestored = 'location_restored';
    case LocationOccupantsReassigned = 'location_occupants_reassigned';

    // Phase 2.5 — Asset Management (SRS FR-AST, FR-PC, FR-QR). As in Phase 2.4,
    // the activity_logs subject morph identifies *which* asset or PC unit the
    // event is about, so the timeline renders "Asset transferred" from the
    // subject type + this label. Every lifecycle transition is written here as
    // well as to `asset_status_history`, in the same transaction, so no status
    // change can occur unaudited (FR-AST-005).
    case AssetCreated = 'asset_created';
    case AssetUpdated = 'asset_updated';
    case AssetStatusChanged = 'asset_status_changed';
    case AssetTransferred = 'asset_transferred';
    case AssetTechnicianAssigned = 'asset_technician_assigned';
    case AssetTechnicianUnassigned = 'asset_technician_unassigned';
    case AssetArchived = 'asset_archived';
    case AssetRestored = 'asset_restored';
    case AssetAttachmentAdded = 'asset_attachment_added';
    case AssetAttachmentRemoved = 'asset_attachment_removed';
    case PcUnitCreated = 'pc_unit_created';
    case PcUnitUpdated = 'pc_unit_updated';
    case PcUnitArchived = 'pc_unit_archived';
    case PcUnitRestored = 'pc_unit_restored';
    case PcSpecificationUpdated = 'pc_specification_updated';
    case QrGenerated = 'qr_generated';
    case QrRegenerated = 'qr_regenerated';
    case QrRevoked = 'qr_revoked';
    case QrPrinted = 'qr_printed';

    // Phase 2.6 — Ticket Management (SRS FR-TKT, FR-ASN). The activity_logs
    // subject morph points at the Ticket, so the timeline renders "Assigned"
    // from the subject type + this label. Lifecycle transitions are written
    // here *and* to `ticket_status_history` in the same transaction, so no
    // status can move unaudited (FR-TKT-005/012).
    case TicketCreated = 'ticket_created';
    case TicketUpdated = 'ticket_updated';
    case TicketStatusChanged = 'ticket_status_changed';
    case TicketPriorityChanged = 'ticket_priority_changed';
    case TicketAssigned = 'ticket_assigned';
    case TicketReassigned = 'ticket_reassigned';
    case TicketAssignmentAccepted = 'ticket_assignment_accepted';
    case TicketAssignmentDeclined = 'ticket_assignment_declined';
    case TicketWorkStarted = 'ticket_work_started';
    case TicketWorkHeld = 'ticket_work_held';
    case TicketWorkCompleted = 'ticket_work_completed';
    case TicketResolutionConfirmed = 'ticket_resolution_confirmed';
    case TicketReopened = 'ticket_reopened';
    case TicketCancelled = 'ticket_cancelled';
    case TicketAutoClosed = 'ticket_auto_closed';
    case TicketMarkedDuplicate = 'ticket_marked_duplicate';
    case TicketArchived = 'ticket_archived';
    case TicketRestored = 'ticket_restored';
    case TicketCommentModerated = 'ticket_comment_moderated';
    case TicketAttachmentAdded = 'ticket_attachment_added';
    case TicketAttachmentRemoved = 'ticket_attachment_removed';

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
            self::LocationCreated => 'Created',
            self::LocationUpdated => 'Updated',
            self::LocationActivated => 'Activated',
            self::LocationDeactivated => 'Deactivated',
            self::LocationArchived => 'Archived',
            self::LocationRestored => 'Restored',
            self::LocationOccupantsReassigned => 'Occupants reassigned',
            self::AssetCreated => 'Created',
            self::AssetUpdated => 'Updated',
            self::AssetStatusChanged => 'Status changed',
            self::AssetTransferred => 'Transferred',
            self::AssetTechnicianAssigned => 'Technician assigned',
            self::AssetTechnicianUnassigned => 'Technician unassigned',
            self::AssetArchived => 'Archived',
            self::AssetRestored => 'Restored',
            self::AssetAttachmentAdded => 'Attachment added',
            self::AssetAttachmentRemoved => 'Attachment removed',
            self::PcUnitCreated => 'Created',
            self::PcUnitUpdated => 'Updated',
            self::PcUnitArchived => 'Archived',
            self::PcUnitRestored => 'Restored',
            self::PcSpecificationUpdated => 'Specification updated',
            self::QrGenerated => 'QR code generated',
            self::QrRegenerated => 'QR code regenerated',
            self::QrRevoked => 'QR code revoked',
            self::QrPrinted => 'QR code printed',
            self::TicketCreated => 'Created',
            self::TicketUpdated => 'Updated',
            self::TicketStatusChanged => 'Status changed',
            self::TicketPriorityChanged => 'Priority changed',
            self::TicketAssigned => 'Assigned',
            self::TicketReassigned => 'Reassigned',
            self::TicketAssignmentAccepted => 'Assignment accepted',
            self::TicketAssignmentDeclined => 'Assignment declined',
            self::TicketWorkStarted => 'Work started',
            self::TicketWorkHeld => 'Work put on hold',
            self::TicketWorkCompleted => 'Work completed',
            self::TicketResolutionConfirmed => 'Resolution confirmed',
            self::TicketReopened => 'Reopened',
            self::TicketCancelled => 'Cancelled',
            self::TicketAutoClosed => 'Closed automatically',
            self::TicketMarkedDuplicate => 'Marked as duplicate',
            self::TicketArchived => 'Archived',
            self::TicketRestored => 'Restored',
            self::TicketCommentModerated => 'Comment moderated',
            self::TicketAttachmentAdded => 'Attachment added',
            self::TicketAttachmentRemoved => 'Attachment removed',
        };
    }
}
