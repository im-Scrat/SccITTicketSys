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

    // WP-I — AI pre-screening (SRS FR-AI-021). Properties carry only
    // non-sensitive metadata (model identifier, category, severity,
    // confidence) — never the summary or recommendation text, matching the
    // PII-minimization posture the AI work packages are held to generally.
    case TicketAiAnalyzed = 'ticket_ai_analyzed';

    // WP-J — the reporter's own FIXED/NOT-FIXED outcome after trying the
    // AI's recommendations. Distinct from TicketWorkCompleted (a
    // technician's physical repair) and TicketStatusChanged (everything
    // else): a reader of the timeline should be able to tell "the reporter
    // fixed this themselves" apart from "a technician did", and "the
    // reporter tried and it didn't work" is not a status change at all — the
    // status stays open, only the escalation state does.
    case TicketFixedByReporter = 'ticket_fixed_by_reporter';
    case TicketNotFixedByReporter = 'ticket_not_fixed_by_reporter';

    // Phase 2.7 — Maintenance (SRS FR-MNT). The activity_logs subject morph
    // points at the MaintenanceRecord, so the timeline renders "Work started"
    // from the subject type plus this label. There is deliberately no
    // `maintenance_status_history` table (SDD DD-55): no FR-MNT requires one,
    // and these rows — written in the same transaction as the change, with
    // before/after values in `properties` — are the lifecycle record.
    case MaintenanceCreated = 'maintenance_created';
    case MaintenanceUpdated = 'maintenance_updated';
    case MaintenanceRescheduled = 'maintenance_rescheduled';
    case MaintenanceReassigned = 'maintenance_reassigned';
    case MaintenanceStarted = 'maintenance_started';
    case MaintenanceHeld = 'maintenance_held';
    case MaintenanceResumed = 'maintenance_resumed';
    case MaintenanceCompleted = 'maintenance_completed';
    case MaintenanceCancelled = 'maintenance_cancelled';
    case MaintenanceArchived = 'maintenance_archived';
    case MaintenanceRestored = 'maintenance_restored';
    case MaintenanceChecklistItemCompleted = 'maintenance_checklist_item_completed';
    case MaintenanceChecklistItemReopened = 'maintenance_checklist_item_reopened';
    case MaintenanceEvidenceAdded = 'maintenance_evidence_added';
    case MaintenanceEvidenceRemoved = 'maintenance_evidence_removed';
    case MaintenanceNoteAdded = 'maintenance_note_added';
    case MaintenanceHardwareReplaced = 'maintenance_hardware_replaced';

    /*
     * WP-2.6b — proof of work submitted from the scanned workflow
     * (SRS FR-MNT-009; SDD DD-50).
     *
     * Its own action rather than a reused `MaintenanceUpdated`, because the
     * timeline has to be able to answer "was this recorded by someone standing
     * at the machine?" — the scan's uuid travels in `properties`, which is the
     * only place that link is visible to an auditor. A scan on its own still
     * writes **no** activity row; this is the business event, and it is the
     * point at which the attempt became one.
     */
    case MaintenanceProofSubmitted = 'maintenance_proof_submitted';

    /*
     * WP-2.6b Stage E — technician work support requests (SRS FR-WSR-011;
     * SDD DD-54).
     *
     * One verb per transition, not a single `work_support_request_updated`
     * carrying a status pair. FR-WSR-011 requires *every* submission, decision,
     * reschedule, clarification, acknowledgement and closure to be logged with
     * before/after values, and DD-54 makes each of them a separately-authorized
     * operation — so the timeline reads as the sequence of decisions it actually
     * was, and a reader can filter for "declines" without parsing properties.
     *
     * There is deliberately **no** `work_support_request_events` table: these
     * rows, with `properties` carrying the superseded values, are the history
     * FR-WSR-004 asks to keep recoverable (the DD-55 stance).
     */
    case WorkSupportRequestSubmitted = 'work_support_request_submitted';
    case WorkSupportRequestApproved = 'work_support_request_approved';
    case WorkSupportRequestDeclined = 'work_support_request_declined';
    case WorkSupportClarificationRequested = 'work_support_clarification_requested';
    case WorkSupportRequestAcknowledged = 'work_support_request_acknowledged';
    case WorkSupportRequestCancelled = 'work_support_request_cancelled';
    case WorkSupportRequestClosed = 'work_support_request_closed';

    /* ---------------------------------------------- announcements (WP-2.7c) */

    case AnnouncementCreated = 'announcement_created';
    case AnnouncementUpdated = 'announcement_updated';
    case AnnouncementPublished = 'announcement_published';
    case AnnouncementUnpublished = 'announcement_unpublished';
    /** A deliberate second notification to the same audience (decision D7). */
    case AnnouncementRenotified = 'announcement_renotified';
    case AnnouncementDeleted = 'announcement_deleted';

    /* ------------------------------ interactive floor plan (Phase 2.8) */

    // The activity_logs subject morph points at the RoomLayout or PcUnit, so
    // the timeline renders "Position changed" from the subject + this label.
    // Position history is these rows, with the before/after coordinates in
    // `properties` — no dedicated history table (the DD-55 stance).
    case LayoutCreated = 'layout_created';
    case LayoutActivated = 'layout_activated';
    case LayoutUpdated = 'layout_updated';
    case AssetPositionChanged = 'asset_position_changed';
    case AssetPositionCleared = 'asset_position_cleared';

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
            self::TicketAiAnalyzed => 'AI pre-screening completed',
            self::TicketFixedByReporter => 'Marked fixed by reporter',
            self::TicketNotFixedByReporter => 'Not fixed by reporter — escalated',
            self::MaintenanceCreated => 'Created',
            self::MaintenanceUpdated => 'Updated',
            self::MaintenanceRescheduled => 'Rescheduled',
            self::MaintenanceReassigned => 'Reassigned',
            self::MaintenanceStarted => 'Work started',
            self::MaintenanceHeld => 'Work put on hold',
            self::MaintenanceResumed => 'Work resumed',
            self::MaintenanceCompleted => 'Work completed',
            self::MaintenanceCancelled => 'Cancelled',
            self::MaintenanceArchived => 'Archived',
            self::MaintenanceRestored => 'Restored',
            self::MaintenanceChecklistItemCompleted => 'Checklist item completed',
            self::MaintenanceChecklistItemReopened => 'Checklist item reopened',
            self::MaintenanceEvidenceAdded => 'Repair evidence added',
            self::MaintenanceEvidenceRemoved => 'Repair evidence removed',
            self::MaintenanceNoteAdded => 'Note added',
            self::MaintenanceHardwareReplaced => 'Hardware replaced',
            self::MaintenanceProofSubmitted => 'Proof of work submitted',

            self::WorkSupportRequestSubmitted => 'Support request submitted',
            self::WorkSupportRequestApproved => 'Support request approved',
            self::WorkSupportRequestDeclined => 'Support request declined',
            self::WorkSupportClarificationRequested => 'Face-to-face discussion requested',
            self::WorkSupportRequestAcknowledged => 'New schedule acknowledged',
            self::WorkSupportRequestCancelled => 'Support request withdrawn',
            self::WorkSupportRequestClosed => 'Support request closed',
            self::AnnouncementCreated => 'Announcement created',
            self::AnnouncementUpdated => 'Announcement updated',
            self::AnnouncementPublished => 'Announcement published',
            self::AnnouncementUnpublished => 'Announcement unpublished',
            self::AnnouncementRenotified => 'Announcement audience notified again',
            self::AnnouncementDeleted => 'Announcement deleted',
            self::LayoutCreated => 'Layout created',
            self::LayoutActivated => 'Layout activated',
            self::LayoutUpdated => 'Layout updated',
            self::AssetPositionChanged => 'Position changed',
            self::AssetPositionCleared => 'Position cleared',
        };
    }
}
