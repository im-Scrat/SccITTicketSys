/**
 * Phase 2.9 — technician monitoring (`/api/admin/technicians/*`).
 *
 * Two kinds of figure, kept apart so the UI can label them honestly:
 * `recorded` is read straight from rows; `calculated` is derived from recorded
 * timestamps. There is no availability figure, because nothing records one.
 */
export interface TechnicianRecorded {
  active_assignments: number
  pending: number
  in_progress: number
  on_hold: number
  completed_assignments: number
  declined_assignments: number
  completed_maintenance: number
  last_activity_at: string | null
}

export interface TechnicianCalculated {
  median_minutes_to_accept: number | null
  median_minutes_to_start: number | null
  median_minutes_to_complete: number | null
  sla_breached: number
  sla_at_risk: number
}

export interface TechnicianSummary {
  id: string
  name: string
  account_status: string
  recorded: TechnicianRecorded
  calculated: TechnicianCalculated
}

export interface TechnicianActiveAssignment {
  id: string | null
  assignment_status: string
  assigned_at: string | null
  number: string | null
  title: string | null
  priority: string | null
  status: { label: string | null; is_open: boolean }
  resolution_due_at: string | null
}

export interface TechnicianDetail extends TechnicianSummary {
  active_assignments: TechnicianActiveAssignment[]
}
