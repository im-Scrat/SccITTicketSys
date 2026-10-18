/**
 * Client mirrors of the Ticket Management API contract (SRS FR-TKT-*, FR-ASN-*).
 *
 * Three projections, matching the server's three resources exactly. The
 * separation is the point: `TicketCard` structurally has no field for an
 * internal comment, a technician or an SLA deadline, so a component handed a
 * card cannot render one by mistake (SDD DD-41).
 */

import type { MaintenanceStatusRef } from '@/features/maintenance/types'

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

/** Cursor pagination — the feed only. */
export interface CursorPaginated<T> {
  data: T[]
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null }
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
}

export interface StatusRef {
  slug: string | null
  label: string | null
  color: string | null
  is_open: boolean
  is_terminal?: boolean
}

/**
 * Who set a ticket's priority, weakest claim first (SDD DD-75): the category's
 * default, the reporter's own choice, a room's minimum, the AI's raise, IT
 * staff (the only choice nothing automatic overrides). `null` — a ticket older
 * than the column.
 */
export type PrioritySource = 'category' | 'reporter' | 'location' | 'ai' | 'manual'

export interface PriorityRef {
  slug: string | null
  label: string | null
  level: number | null
  color?: string | null
  /**
   * Staff projections only (the full record for IT staff, the staff list). A
   * reporter never receives it, so they are never told in so many words which
   * rule set their ticket's level. That is all withholding it does: the level
   * itself is shown to the reporter by design, so a Low that comes back High
   * still shows that the room has a minimum — the floor's value is not
   * treated as confidential.
   */
  source?: PrioritySource | null
}

export interface CategoryRef {
  slug: string | null
  label: string | null
}

/**
 * A category plus the reporter's own words for an "Other" one (SDD DD-77).
 * The full record and the staff list only — never the community card, where
 * free text written for the IT team does not belong.
 */
export interface CategoryWithDetail extends CategoryRef {
  detail: string | null
}

export interface PersonRef {
  id?: string
  name: string | null
  email?: string | null
}

/** Equipment as a label — never asset data (SDD DD-38). */
export interface PcUnitRef {
  id?: string
  label: string
  identifier: string
}

/**
 * The other equipment a ticket can name — a printer, a projector — in the same
 * label-only shape as a PC (SDD DD-79). A ticket names at most one item: a PC
 * or an asset, never both.
 */
export type EquipmentRef = PcUnitRef

export interface LocationRef {
  room: string | null
  floor?: string | null
  building: string | null
}

/**
 * The restricted community card. What it omits is the security guarantee:
 * no description beyond an excerpt, no attachments, no internal comments, no
 * technician, no SLA, no AI fields.
 */
export interface TicketCard {
  id: string
  ticket_number: string
  title: string
  excerpt: string
  status: StatusRef
  priority: PriorityRef
  category: CategoryRef
  pc_unit: Pick<PcUnitRef, 'label' | 'identifier'> | null
  /** No id on the card: a requester names the item, never links to its record. */
  asset: Pick<EquipmentRef, 'label' | 'identifier'> | null
  location: LocationRef | null
  /**
   * `label` is what to show: "You", "A colleague", or — for an Administrator
   * only — the name. `name` is null for anyone but the reporter and an
   * Administrator (Phase 2.9): another teacher never receives who reported it.
   */
  reporter: { name: string | null; label: string }
  upvote_count: number
  comment_count: number
  has_voted: boolean
  is_mine: boolean
  created_at: string | null
}

/** A staff table row — carries the operational columns a card must not. */
export interface TicketRow {
  id: string
  ticket_number: string
  title: string
  status: StatusRef
  priority: PriorityRef
  category: CategoryWithDetail
  reporter: PersonRef
  technician: PersonRef | null
  pc_unit: PcUnitRef | null
  asset: EquipmentRef | null
  location: LocationRef | null
  /**
   * Phase 2.10, administrator directory only: the room the ticket was
   * *reported* in (archived or not) — where report figures counted it. May
   * differ from `location`, which prefers where the PC is now.
   */
  reported_location?: { room: string; building: string | null; archived: boolean } | null
  upvote_count: number
  comment_count: number
  attachment_count: number
  resolution_due_at: string | null
  sla: SlaPosture | null
  created_at: string | null
  updated_at: string | null
  archived: boolean
}

export interface SlaPosture {
  response_breached: boolean
  resolution_breached: boolean
  breached: boolean
  at_risk: boolean
  minutes_to_resolution: number | null
}

/** The full record — reporter's own, assigned technician's, or administrator's. */
export interface TicketDetail {
  id: string
  ticket_number: string
  title: string
  description: string
  source: string
  status: StatusRef
  priority: PriorityRef
  category: CategoryWithDetail
  reporter: PersonRef | null
  technician: PersonRef | null
  is_assigned: boolean
  pc_unit: PcUnitRef | null
  asset: EquipmentRef | null
  location: LocationRef | null
  upvote_count: number
  comment_count: number
  attachment_count: number
  has_voted: boolean
  tags?: Array<{ slug: string; label: string }>
  duplicate_of: { id: string; ticket_number: string; title: string } | null
  reported_at: string | null
  first_response_at: string | null
  resolved_at: string | null
  closed_at: string | null
  reopened_at: string | null
  sla: { response_due_at: string | null; resolution_due_at: string | null } | null
  /** Null until the AI phase writes it — rendered as "not analysed", never faked. */
  ai: {
    summary: string | null
    confidence: number | null
    estimated_minutes: number | null
    technician_required: boolean
    analysed: boolean
  } | null
  attachments?: TicketAttachment[]
  archived: boolean
  archived_at: string | null
}

export interface TicketTransition {
  value: string
  label: string
  color: string
  terminal: boolean
}

/** What the server says this caller may do — the client never re-derives it. */
export interface TicketAbilities {
  update: boolean
  comment: boolean
  comment_internal: boolean
  vote: boolean
  attach: boolean
  confirm_resolution: boolean
  reopen: boolean
  cancel: boolean
  assign: boolean
  change_priority: boolean
  /** WP-J — the reporter's own open ticket: resolve it after trying the AI's steps. */
  mark_fixed?: boolean
  /** WP-J — the reporter tried the steps and it still isn't working (once per open period). */
  report_not_fixed?: boolean
}

/** One ordered step from the AI pre-screening (WP-I). */
export interface AiRecommendationStep {
  step: number
  text: string
  is_completed: boolean
}

/**
 * The AI pre-screening for one ticket (WP-I) — always advisory.
 *
 * `meets_confidence_threshold` is null when no threshold is configured or the
 * model gave no confidence; the client treats null as "not known to meet it"
 * and never presents the analysis as fact on that basis.
 */
export interface TicketAiAnalysis {
  ai_generated: true
  advisory: true
  confidence: number | null
  confidence_threshold: number | null
  meets_confidence_threshold: boolean | null
  problem_category: string | null
  severity: string | null
  estimated_resolution_minutes: number | null
  technician_required: boolean
  summary: string | null
  recommendations?: AiRecommendationStep[]
  analyzed_at: string | null
}

export type ReporterOutcome = 'fixed' | 'not_fixed'

export type TicketAiAnalysisEnvelope =
  /**
   * `pending` (Phase 2.9): AI is configured and the ticket is still inside
   * its analysis retry window, so an analysis may yet arrive.
   */
  | { data: null; meta: { available: false; pending?: boolean } }
  | {
      data: TicketAiAnalysis
      meta: { available: true; reporter_outcome: ReporterOutcome | null }
    }

export interface AssignmentMeta {
  status: string
  assigned_at: string | null
  accepted_at: string | null
  started_at: string | null
  completed_at: string | null
  can: {
    accept: boolean
    decline: boolean
    start: boolean
    hold: boolean
    complete: boolean
  }
}

/**
 * One maintenance record a ticket's repair produced (WP-K). The status is the
 * Maintenance module's own shape, tone included, so its badge renders it.
 */
export interface TicketRepairRecord {
  id: string
  title: string
  status: MaintenanceStatusRef
  completed_at: string | null
}

/**
 * The technician route's repair section (WP-K). `can_start` is the server's
 * whole answer — an active assignment, a PC or other item to hold the history
 * (SDD DD-79), no record already open, and `maintenance.create` — so the client
 * never re-derives it.
 */
export interface TicketRepairMeta {
  records: TicketRepairRecord[]
  can_start: boolean
}

/**
 * What the server volunteers alongside the record. Every field is optional
 * because each surface sends only what it knows: the requester route sends
 * `can` and the reopen window, the technician route sends the assignment and
 * `read_only`, the administrator route sends SLA posture.
 */
export interface TicketDetailMeta {
  transitions?: TicketTransition[]
  can?: TicketAbilities
  reopen_window_days?: number
  sla?: SlaPosture
  assignment?: AssignmentMeta | null
  read_only?: boolean
  repair?: TicketRepairMeta
}

export interface TicketDetailEnvelope {
  data: TicketDetail
  meta?: TicketDetailMeta
  message?: string
}

/** A ticket the caller may only see as a card comes back in this shape. */
export interface TicketCardEnvelope {
  data: TicketCard
}

/**
 * The response to `GET /tickets/{uuid}`.
 *
 * One route, two projections: the reporter (or an administrator, or the
 * assigned technician) receives the full record, and any other requester
 * receives the restricted community card. Typing it as a union rather than as
 * `TicketDetail` is deliberate — it makes the client *unable* to reach for a
 * description or an attachment without first asking which projection arrived,
 * so the server's redaction cannot be quietly assumed away.
 */
export type TicketShowEnvelope = TicketDetailEnvelope | TicketCardEnvelope

/**
 * Did the server send the full record?
 *
 * Discriminated on `description`, which exists only on the full projection —
 * the card carries an `excerpt` instead and has no field that could hold one.
 */
export function isFullTicket(envelope: TicketShowEnvelope): envelope is TicketDetailEnvelope {
  return 'description' in envelope.data
}

export interface TicketComment {
  id: string
  body: string | null
  removed: boolean
  is_internal: boolean
  is_edited: boolean
  edited_at: string | null
  /** `id` is sent to Administrators only (Phase 2.9); null for everyone else. */
  author: { id: string | null; name: string | null; role: string | null } | null
  is_mine: boolean
  /** The thread's top-level comment, on a reply; null on a top-level comment. */
  parent_id?: string | null
  /**
   * On a top-level comment in the listing: its replies, oldest first. Threads
   * are one level deep (SDD DD-74), so a reply never carries replies of its own.
   */
  replies?: TicketComment[]
  moderated_by: string | null
  created_at: string | null
}

export interface TicketAttachment {
  id: string
  filename: string
  mime_type: string | null
  file_size: number | null
  is_image: boolean
  uploaded_by: string | null
  uploaded_at: string | null
  url: string
}

export interface OptionItem {
  value: string
  label: string
  description?: string | null
}

export interface StatusOption extends OptionItem {
  color: string
  is_open: boolean
  is_terminal: boolean
}

/**
 * A category as the options endpoint offers it. `requires_detail` marks the
 * "Other" kinds that ask the reporter to say what the problem is (SDD DD-77) —
 * category data, so the client never keeps its own list of slugs.
 */
export interface CategoryOption extends OptionItem {
  requires_detail?: boolean
}

/**
 * Every role receives the priority list (SDD DD-75), ordered most urgent first.
 * The SLA minutes are staff-only: a requester gets the name, level and colour,
 * so both minutes are absent for them — check with `!= null`, never `!== null`.
 */
export interface PriorityOption extends OptionItem {
  level: number
  color: string
  response_minutes?: number | null
  resolution_minutes?: number | null
}

export interface TechnicianOption extends OptionItem {
  role: string
}

/**
 * Role-shaped: `technicians` is empty for a requester, and a requester's
 * priorities carry no SLA minutes. `uploads.max_kb` is the server's per-file
 * ceiling, so a photo can be refused before it is sent rather than after.
 */
export interface TicketOptions {
  categories: CategoryOption[]
  statuses: StatusOption[]
  tags: OptionItem[]
  priorities: PriorityOption[]
  technicians: TechnicianOption[]
  uploads?: { max_kb: number }
}

export interface TicketDashboard {
  summary: {
    open_backlog: number
    unassigned: number
    breached: number
    at_risk: number
    lead_hours: number
    awaiting_confirmation: number
  }
  by_status: Array<{
    key: string
    label: string
    count: number
    color: string | null
    is_open: boolean
  }>
  by_priority: Array<{ key: string; label: string; count: number; color: string | null }>
  technician_workload: Array<{ id: string; name: string; count: number }>
  triage_queue: TicketRow[]
  awaiting_confirmation: TicketRow[]
  auto_close_days: number
  generated_at: string
}

export type TicketSortColumn =
  | 'created_at'
  | 'updated_at'
  | 'ticket_number'
  | 'title'
  | 'upvotes'
  | 'comments'
  | 'status'
  | 'priority'
  | 'category'
  | 'reporter'
  | 'technician'
  | 'resolution_due_at'

export type FeedSort = 'recent' | 'upvotes' | 'comments' | 'priority'

export interface TicketParams {
  search?: string
  status?: string
  priority?: string
  category?: string
  room?: string
  building?: string
  pc_unit?: string
  technician?: string
  mine?: boolean
  has_pc_unit?: boolean
  /**
   * SLA and confirmation filters are sent as `1`. Laravel's `boolean` rule accepts
   * the number but not the string `"true"` that axios sends for `true`, so a
   * `true` here is a 422 — which is what the Ticket Management tiles used to cause.
   */
  breached?: 1
  awaiting_confirmation?: 1
  /**
   * The technician queue only (`GET /tickets/assigned`). `assigned` = every ticket
   * the caller owns, whatever its status — the set the dashboard's figures count;
   * `active` (the default, omitted) = tickets with a live assignment.
   */
  scope?: 'active' | 'assigned'
  /** The technician queue only: unresolved tickets due within the next four hours. */
  due_soon?: 1
  /**
   * The requester's own list only (Phase 2.9): tickets whose `resolved_at` falls
   * inside the last N days — the list behind the dashboard's "Resolved (30 days)".
   * The server rejects anything outside 1–365 with 422.
   */
  resolved_within?: number
  /**
   * Administrative directory only (Phase 2.9): open tickets whose reporter said
   * NOT FIXED in the current period. Sent as `1` — Laravel's `boolean` rule
   * does not accept the string `"true"` that axios would send for `true`.
   */
  escalated?: 1
  /**
   * Administrative directory only (Phase 2.10): what a report figure's
   * drill-down carries, so the list totals exactly to the figure. Dates are
   * school-local and inclusive; flags are sent as `1`.
   */
  reported_from?: string
  reported_to?: string
  room_type?: string
  without_room?: 1
  problem_area?: string
  asset?: string
  exclude_duplicates?: 1
  exclude_cancelled?: 1
  include_closed?: boolean
  trashed?: 'without' | 'with' | 'only'
  sort?: TicketSortColumn | FeedSort
  direction?: 'asc' | 'desc'
  per_page?: number
  page?: number
  cursor?: string
}

/**
 * Phase 2.9 — one entry of a ticket's History tab (`GET /tickets/{id}/timeline`).
 *
 * A projection, not the raw log: a requester sees structural facts (status,
 * priority, "assigned to a technician") and their own notes only; staff see
 * names and every note. Comments are not here — they have their own tab.
 */
export type TimelineEntry = {
  at: string | null
  actor: string
  note: string | null
} & (
  | { kind: 'created' }
  | { kind: 'status' | 'priority'; from: string | null; to: string | null }
  | { kind: 'outcome'; outcome: 'fixed' | 'not_fixed'; from: string | null; to: string | null }
  | {
      kind: 'assignment' | 'assignment_response'
      reassigned: boolean
      technician: string | null
      assignment_status?: string | null
    }
)
