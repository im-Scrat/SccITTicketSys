/**
 * The scanned technician workflow (SRS FR-QR-005/010/011/012, FR-MNT-009/012).
 *
 * These shapes mirror the server's **scan-scoped** projections, not the asset
 * record. `ScannedPcUnit` has no field for price, supplier, warranty, custodian,
 * serial number, asset tag, hostname or addresses because
 * `ScannedPcUnitResource` has none either — the type is deliberately as narrow
 * as the payload, so a future widening of one has to be a deliberate widening
 * of both.
 */

/** What the scan endpoint says happens next. It never carries machine data. */
export type ScanNext = 'sign-in' | 'panel' | 'refused'

export type ScanRefusalReason =
  'not_authorized' | 'label_unknown' | 'label_inactive' | 'no_workflow' | 'not_reachable'

export interface ScanOutcome {
  next: ScanNext
  /** Present only on `panel`: the handle a proof submission quotes. */
  scan_id?: string
  code?: string
  reason?: ScanRefusalReason
  message?: string
}

export interface ScannedLocation {
  room: string | null
  floor: string | null
  building: string | null
}

export interface ScannedSpecification {
  processor?: string | null
  ram?: string | null
  storage?: string | null
  operating_system?: string | null
  [key: string]: unknown
}

export interface ScannedComponent {
  name: string | null
  category: string | null
  category_label: string | null
  installed_at: string | null
}

export interface ScannedTicket {
  id: string
  number: string
  title: string
  status: string | null
  priority: string | null
}

export interface ScannedChecklistItem {
  id: string
  label: string
  is_required: boolean
  is_completed: boolean
}

export interface ScannedMaintenance {
  id: string
  title: string
  type: string | null
  status: string
  status_label: string
  scheduled_for: string | null
  started_at: string | null
  diagnosis: string | null
  root_cause: string | null
  resolution: string | null
  checklist: ScannedChecklistItem[]
}

export interface ScannedQrState {
  status: string
  status_label: string
  location_label: string | null
}

/** The scan-scoped panel — all fifteen keys the server will return, and no more. */
export interface ScannedPcUnit {
  id: string
  unit_code: string
  pc_name: string | null
  brand: string | null
  model: string | null
  location: ScannedLocation | null
  status: string
  status_label: string
  condition: string
  condition_label: string
  specification: ScannedSpecification | null
  installed_components: ScannedComponent[]
  active_tickets: ScannedTicket[]
  active_maintenance: ScannedMaintenance[]
  qr: ScannedQrState | null
}

/** One job the technician may submit proof against. */
export interface WorkTarget {
  id: string
  title: string
  type: string | null
  status: string
  status_label: string
  scheduled_for: string | null
}

export interface WorkTargets {
  targets: WorkTarget[]
  /** Whether this caller could open a record when the machine carries none. */
  may_open_record: boolean
}

export type ProofOutcome = 'in_progress' | 'on_hold' | 'completed'
export type EvidenceStage = 'before' | 'during' | 'after'

export interface ProofEvidence {
  id: string
  stage: EvidenceStage
  filename: string | null
  size: number | null
  caption: string | null
  created_at: string | null
}

export interface ProofResult {
  created: boolean
  replayed: boolean
  evidence_added: number
  evidence_skipped: number
  maintenance: {
    id: string
    title: string
    type: string | null
    status: string
    status_label: string
    ticket: string | null
    diagnosis: string | null
    root_cause: string | null
    resolution: string | null
    started_at: string | null
    completed_at: string | null
    checklist: ScannedChecklistItem[]
  }
  evidence: ProofEvidence[]
}

export interface ProofSubmission {
  scanId: string
  maintenanceId?: string
  resolution: string
  diagnosis?: string
  outcome: ProofOutcome
  evidenceStage: EvidenceStage
  evidence: File[]
  caption?: string
}
