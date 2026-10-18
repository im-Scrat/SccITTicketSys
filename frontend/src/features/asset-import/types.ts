/** Phase 2.10 R6 — the asset import, as the API serves it (SDD DD-96). */

export type ImportMode = 'pc_units' | 'assets' | 'mixed'

export type ImportStatus =
  | 'reading'
  | 'mapping'
  | 'validating'
  | 'review'
  | 'queued'
  | 'importing'
  | 'finished'
  | 'cancelled'
  | 'expired'
  | 'unreadable'

export type ImportOutcome = 'complete' | 'partial' | 'nothing_new' | 'failed' | 'stopped'

export type RowStatus = 'ready' | 'needs_review' | 'invalid' | 'duplicate' | 'excluded'

export type Confidence = 'exact' | 'synonym' | 'likely' | 'ambiguous' | 'none'

export interface ImportColumn {
  index: number
  letter: string
  header: string
  samples: string[]
  target: string | null
  confidence: Confidence
  alternatives: string[]
  never_imported: string | null
  quantity: boolean
}

export interface ImportTarget {
  key: string
  label: string
  group: string
  destination: string
  hint: string | null
  required: boolean
  /** The saved value each register reads this target from (dotted for specification). */
  payload: { pc_unit: string | null; asset: string | null }
}

export interface Option {
  value: string
  label: string
}

export interface MeaningValue {
  raw: string
  normalized: string
  rows: number
  suggested: string | null
  confidence: Confidence
  choices: string[]
  hint: string | null
  meaning: string | null
}

export interface MeaningTable {
  field: string
  column: number
  target: string
  options: Option[]
  values: MeaningValue[]
}

export interface DateSummary {
  required: boolean
  ambiguous: number
  evidence: 'dmy' | 'mdy' | null
  samples: Array<{ raw: string; dmy: string | null; mdy: string | null }>
}

export interface ImportPlan {
  pc_units: number
  assets: number
  total: number
  rooms: number
  without_room: number
  duplicates: number
  excluded: number
  blocking: number
  invalid: number
  needs_review: number
  catalog_entries: Array<{ uuid: string; label: string; rows: number }>
  revision: number
}

export interface ImportResult {
  outcome: ImportOutcome
  tone: 'success' | 'warning' | 'neutral'
  headline: string
  detail: string
  created: number
  created_pc_units: number
  created_assets: number
  skipped_duplicates: number
  duplicates_at_save: number
  failed: number
  not_attempted: number
  excluded: number
  rooms: number
  buildings: number
  without_room: number
  catalog_created: number
}

export interface AssetImport {
  id: string
  entity_mode: ImportMode
  entity_mode_label: string
  status: ImportStatus
  status_label: string
  outcome: ImportOutcome | null
  outcome_label: string | null
  revision: number
  file: { name: string; extension: string; size_bytes: number; available: boolean }
  duplicate_of: {
    id: string
    name: string
    created_at: string | null
    outcome: ImportOutcome | null
  } | null
  source: {
    sheets: string[]
    sheet: string | null
    header_row: number | null
    encoding: string | null
    delimiter: string | null
    columns: number | null
    template_match: boolean
    error: string | null
    error_code: string | null
  }
  columns: ImportColumn[]
  mapping: Record<string, string>
  value_mappings: Record<string, Record<string, string>>
  defaults: Record<string, string | boolean>
  date_order: 'dmy' | 'mdy' | null
  targets: ImportTarget[]
  default_options: {
    category: Option[]
    pc_status: Option[]
    asset_status: Option[]
    condition: Option[]
  }
  meaning_tables: MeaningTable[]
  date_summary: DateSummary | null
  row_counts: Record<RowStatus, number> | null
  total_rows: number
  plan: ImportPlan | null
  progress: { done: number; total: number; created: number } | null
  result: ImportResult | null
  created_by: string | null
  confirmed_by: string | null
  confirmed_at: string | null
  started_at: string | null
  finished_at: string | null
  stop_requested: boolean
  reviewed_at: string | null
  expires_at: string | null
  created_at: string | null
  can: { cancel: boolean; stop: boolean; retry: boolean; download_file: boolean }
}

export interface AssetImportListItem {
  id: string
  file_name: string
  entity_mode: ImportMode
  entity_mode_label: string
  status: ImportStatus
  status_label: string
  outcome: ImportOutcome | null
  headline: string | null
  tone: 'success' | 'warning' | 'neutral' | null
  total_rows: number
  created: number | null
  created_by: string | null
  created_at: string | null
  finished_at: string | null
}

export type IssueSeverity = 'error' | 'duplicate' | 'review' | 'warning' | 'info'

export interface ImportIssue {
  code: string
  severity: IssueSeverity
  field: string | null
  message: string
  action:
    | 'correct'
    | 'acknowledge'
    | 'decide_location'
    | 'decide_catalog'
    | 'decide_reference'
    | 'exclude'
    | 'none'
  scope: 'row' | 'link' | 'file'
  related: Record<string, unknown>
}

export interface ImportRow {
  row_number: number
  entity_type: 'pc_unit' | 'asset' | null
  entity_label: string | null
  status: RowStatus
  status_label: string
  is_excluded: boolean
  outcome: 'created' | 'skipped_duplicate' | 'failed' | 'not_attempted' | null
  outcome_label: string | null
  failure_code: string | null
  previous_failure_code: string | null
  issues: ImportIssue[]
  acknowledged: string[]
  values: Record<string, unknown>
  sources: Record<string, string>
  original: Record<string, string>
  corrections: Record<
    string,
    { value: string | null; at: string | null; assign_anyway: boolean | null }
  >
  location: { id: string; source_text: string; resolution: string; room: string | null } | null
  catalog: { id: string; label: string; decision: string } | null
  created: { type: 'pc_unit' | 'asset'; id: string; label: string } | null
}

export interface RoomCandidate {
  uuid: string
  label: string
  code: string
  reason: string
  selectable: boolean
  note?: string
}

export interface ImportLocation {
  id: string
  source_text: string
  is_blank: boolean
  parts: Record<string, string | null>
  match: 'matched' | 'ambiguous' | 'unmatched' | 'not_selectable'
  match_label: string
  candidates: RoomCandidate[]
  resolution: 'pending' | 'room' | 'unassigned'
  resolution_label: string
  room: { id: string; label: string; selectable: boolean; archived: boolean } | null
  assign_anyway: boolean
  row_count: number
  decided: boolean
  decided_at: string | null
}

export interface CatalogEntry {
  id: string
  component_type: string
  component_label: string
  manufacturer_name: string | null
  model_name: string
  model_number: string | null
  match: 'existing' | 'new' | 'suggested' | 'blocked'
  match_label: string
  matched_model: { id: number; label: string } | null
  suggestions: Array<{ model_id: number; label: string; reason: string; archived: boolean }>
  decision: 'pending' | 'approved' | 'existing' | 'rejected'
  decision_label: string
  chosen_model: { id: number; label: string } | null
  row_count: number
  decided: boolean
}

export interface RoomOption {
  id: string
  label: string
  code: string
  selectable: boolean
}

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

export interface MappingPayload {
  mapping: Record<string, string | null>
  value_mappings: Record<string, Record<string, string | null>>
  defaults: Record<string, string | boolean | null>
  date_order: 'dmy' | 'mdy' | null
}
