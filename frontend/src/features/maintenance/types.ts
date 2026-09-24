/**
 * Client mirrors of the Maintenance API contract (SRS FR-MNT-001..008/010/011).
 *
 * One list shape and one detail shape, because there is one audience: every
 * surface that renders these is staff doing or overseeing the work. Tickets
 * needed a redacted third projection because a Teacher can discover another
 * requester's ticket (SDD DD-41); nothing in FR-MNT lets anyone reach a
 * maintenance record they are not party to, so there is nothing to redact.
 */

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

export type MaintenanceStatusValue =
  'scheduled' | 'in_progress' | 'on_hold' | 'completed' | 'cancelled'

/** Presentation tone comes from the API — the client never picks a status hue. */
export type MaintenanceTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

export interface MaintenanceStatusRef {
  value: MaintenanceStatusValue
  label: string
  tone: MaintenanceTone
  is_open: boolean
}

export interface MaintenanceTypeRef {
  slug: string | null
  label: string | null
  is_preventive: boolean
}

export interface PersonRef {
  id: string
  name: string | null
}

/** Equipment as a label — never asset data (SDD DD-38). */
export interface TargetRef {
  kind: 'pc_unit' | 'asset'
  id: string
  label: string
  identifier: string
}

export interface LocationRef {
  room: string | null
  building: string | null
}

export interface TicketRef {
  id: string
  number: string
  title: string
}

export interface MaintenanceListItem {
  id: string
  title: string
  status: MaintenanceStatusRef
  type: MaintenanceTypeRef
  technician: PersonRef | null
  created_by: PersonRef | null
  target: TargetRef | null
  location: LocationRef | null
  ticket: TicketRef | null
  checklist: { total: number; completed: number }
  evidence_count: number
  note_count: number
  downtime_minutes: number | null
  labor_hours: string | null
  cost: string | null
  scheduled_for: string | null
  overdue: boolean
  started_at: string | null
  completed_at: string | null
  maintenance_date: string | null
  created_at: string | null
  updated_at: string | null
  archived: boolean
}

export interface ChecklistItem {
  id: number
  label: string
  is_required: boolean
  is_completed: boolean
  sort_order: number
  remarks: string | null
  completed_by: PersonRef | null
  completed_at: string | null
}

export type EvidenceStage = 'before' | 'during' | 'after'

export interface EvidenceItem {
  id: string
  image_type: EvidenceStage
  image_type_label: string
  filename: string | null
  mime_type: string | null
  kind: 'image' | 'document'
  size: number | null
  caption: string | null
  uploaded_by: PersonRef | null
  created_at: string | null
}

export interface MaintenanceNote {
  id: number
  body: string
  author: PersonRef | null
  created_at: string | null
}

export interface ComponentRef {
  id: number
  label: string
  type: string
}

export interface AssetRef {
  id: string
  label: string
  asset_tag: string
}

export interface HardwareReplacement {
  id: number
  quantity: number
  reason: string | null
  warranty_months: number | null
  replaced_at: string | null
  old_component: ComponentRef | null
  new_component: ComponentRef | null
  old_asset: AssetRef | null
  new_asset: AssetRef | null
}

/**
 * A transition the server says this caller may attempt.
 *
 * `blocked_by` is why they cannot yet — computed by `MaintenanceLifecycle`, the
 * same code that would refuse the request. A button the API would reject is
 * worse than no button, and a silently missing one teaches nothing.
 */
export interface TransitionOption {
  value: MaintenanceStatusValue
  label: string
  blocked_by: string[]
}

export interface MaintenanceAbilities {
  update: boolean
  complete: boolean
  reassign: boolean
  manage_evidence: boolean
  record_replacement: boolean
  archive: boolean
}

export interface PcStateBefore {
  status: string
  status_label: string
  condition: string | null
  condition_label: string | null
}

export interface MaintenanceDetail extends MaintenanceListItem {
  diagnosis: string | null
  root_cause: string | null
  resolution: string | null
  preventive_recommendation: string | null
  pc_state_before: PcStateBefore | null
  checklist_items: ChecklistItem[]
  evidence: EvidenceItem[]
  notes: MaintenanceNote[]
  hardware_replacements: HardwareReplacement[]
  available_transitions: TransitionOption[]
  abilities: MaintenanceAbilities
}

/** Open work already targeting the same machine — a warning, never a refusal. */
export interface ConcurrentWarning {
  id: string
  title: string
  status: MaintenanceStatusValue
  status_label: string
  type: string | null
  technician: string | null
  scheduled_for: string | null
}

export interface MaintenanceDetailEnvelope {
  data: MaintenanceDetail
  meta?: { concurrent?: ConcurrentWarning[] }
}

export interface MaintenanceTypeOption {
  value: string
  label: string
  description: string | null
  is_preventive: boolean
  /** Corrective work must show proof to complete (FR-MNT-010). */
  requires_evidence: boolean
  has_checklist: boolean
}

export interface MaintenanceOptions {
  types: MaintenanceTypeOption[]
  statuses: { value: MaintenanceStatusValue; label: string }[]
  evidence_types: { value: EvidenceStage; label: string }[]
  /** Administrator-only; an empty list for everyone else. */
  technicians: { value: string; label: string }[]
  checklist_templates: { value: number; label: string; item_count: number }[]
}

export interface MaintenanceDashboard {
  cadence: { interval_days: number; lead_days: number }
  posture: {
    open: number
    scheduled: number
    in_progress: number
    on_hold: number
    overdue: number
    due_soon: number
    unscheduled: number
    lead_days: number
  }
  throughput: {
    completed: number
    downtime_minutes: number
    labor_hours: number
    cost: number
    window_days: number
  }
  status_mix: { key: string; label: string; count: number }[]
  type_mix: { key: string; label: string; count: number; is_preventive: boolean }[]
  technician_workload: { key: string; label: string; count: number }[]
}

export interface MaintenanceParams {
  search?: string
  status?: string
  type?: string
  preventive?: string
  technician?: string
  pc_unit?: string
  asset?: string
  ticket?: string
  room?: string
  building?: string
  scheduled_from?: string
  scheduled_to?: string
  overdue?: string
  scope?: 'own' | 'all'
  trashed?: 'without' | 'with' | 'only'
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

/*
 * The audit timeline shape is **not** redefined here.
 *
 * `activity_logs` is one platform-wide vocabulary, and `AuditTimeline` renders
 * it identically for accounts, locations, assets, tickets and now maintenance —
 * so this slice imports `@/types/activity` rather than mirroring it. A private
 * copy would drift the first time a field was added.
 */
