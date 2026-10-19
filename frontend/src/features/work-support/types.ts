/**
 * Technician work support requests (SRS FR-WSR-001..014).
 *
 * The status union is the authoritative six and nothing else — FR-WSR-004 fixes
 * the set, and a client-side "pending" or "in review" would be a seventh state
 * the server has never heard of. Where the UI wants to group them, it groups
 * these; it does not invent one.
 */
export type WorkSupportStatus =
  'submitted' | 'clarification_requested' | 'approved' | 'declined' | 'cancelled' | 'closed'

export interface SupportRequestItem {
  name: string
  from_catalog: boolean
  quantity: number
  remarks: string | null
}

export interface SupportRequestAttachment {
  id: string
  filename: string
  kind: 'image' | 'document'
  size: number | null
  caption: string | null
}

export interface SupportRequestDecision {
  by: { id: string; name: string } | null
  at: string | null

  rescheduled_to: string | null
  reschedule_reason: string | null
  acknowledged_at: string | null

  clarification_reason: string | null
  proposed_meeting_at: string | null

  decline_reason: string | null

  cancelled_at: string | null
  cancellation_note: string | null
  cancelled_by: string | null

  closed_at: string | null
}

export interface SupportRequest {
  id: string
  status: WorkSupportStatus
  status_label: string
  is_undecided: boolean
  is_terminal: boolean

  explanation: string
  submitted_at: string | null

  technician: { id: string; name: string } | null
  pc_unit: { id: string; unit_code: string; pc_name: string | null; room: string | null } | null
  maintenance: {
    id: string
    title: string
    status: string
    status_label: string
    scheduled_for: string | null
  } | null
  ticket: { id: string; number: string; title: string } | null

  items: SupportRequestItem[]
  attachments: SupportRequestAttachment[]
  decision: SupportRequestDecision
}

/** What a technician sends to raise one. No PC identifier — the code carries it. */
export interface RaiseSupportRequest {
  maintenanceId?: string
  explanation: string
  items: { hardware_model?: number; description?: string; quantity: number; remarks?: string }[]
  evidence: File[]
  caption?: string
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
}

/* ------------------------------------------- Stage F: the combined feed */

/** The discriminator on a submission-history entry (SRS FR-WSR-009). */
export type SubmissionKind = 'proof_of_work' | 'support_request'

/**
 * A proof-of-work submission as the history lists it.
 *
 * Deliberately narrower than the maintenance module's own detail type: no cost,
 * no labour hours, no custodian, no register identifiers. The feed must not be a
 * wider projection than the scan panel that produced the submission (DD-49).
 */
export interface ProofOfWorkSubmission {
  id: string
  title: string
  type: string | null
  status: string
  status_label: string
  pc_unit: { id: string; unit_code: string; pc_name: string | null } | null
  ticket: string | null
  resolution: string | null
  started_at: string | null
  completed_at: string | null
  evidence: {
    stage: 'before' | 'during' | 'after'
    filename: string | null
    kind: 'image' | 'document'
  }[]
}

/**
 * One entry in the combined history. Exactly one body is present, and `kind`
 * says which — the client never infers it from which field happens to be null.
 */
export type TechnicianSubmission =
  | { kind: 'proof_of_work'; submitted_at: string | null; proof_of_work: ProofOfWorkSubmission }
  | { kind: 'support_request'; submitted_at: string | null; support_request: SupportRequest }
