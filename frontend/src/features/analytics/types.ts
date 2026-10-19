/**
 * Report payloads (Phase 2.10). Mirrors `TicketOverview` and `ReportOptions`
 * on the server; every label and sentence arrives composed there, in the
 * school's timezone, so the browser's own zone never changes what a figure
 * means.
 */

/** A link to the ticket list a figure counts. Null when no list can match it exactly. */
export interface Drilldown {
  path: string
  query: Record<string, string>
}

export interface ReportBucket {
  index: number
  key: string
  label: string
  short_label: string
  starts_at: string
  ends_at: string
  /** Null before ticket records began — unknown, never zero. */
  count: number | null
  partial: boolean
  in_progress: boolean
  before_records: boolean
  drilldown: Drilldown | null
}

export interface StatusRow {
  slug: string
  label: string
  count: number
  pct: number | null
  is_open: boolean
  is_terminal: boolean
  drilldown: Drilldown | null
}

export interface RankingRow {
  rank: number
  count: number
  open: number
  share_pct: number | null
  room: {
    id: string
    name: string
    code: string
    room_type: string
    room_type_label: string
    label: string
    building: { id: string; name: string } | null
    archived: boolean
    inactive: boolean
  }
  drilldown: Drilldown | null
}

export interface TypeRow {
  type: 'laboratory' | 'office' | 'other' | 'unspecified'
  label: string
  count: number
  rooms_with_problems: number | null
  rooms_total: number | null
  per_room: number | null
  drilldown: Drilldown | null
}

export interface TicketOverviewData {
  kpis: {
    reported: {
      value: number
      comparison: number | null
      change: number | null
      change_pct: number | null
      direction: 'up' | 'down' | 'same' | null
      duplicates: number
      cancelled: number
      drilldown: Drilldown | null
    }
    daily_average: {
      value: number
      comparison: number | null
      days: number
      comparison_days: number | null
    } | null
    open_now: {
      value: number
      share_pct: number | null
      awaiting_confirmation: number
      drilldown: Drilldown | null
    }
    resolved_in_period: { value: number; drilldown: null }
  }
  status_distribution: { total: number; rows: StatusRow[] }
  series: {
    granularity: 'day' | 'week' | 'month' | 'year'
    unit_label: string
    buckets: ReportBucket[]
    comparison: {
      buckets: ReportBucket[]
      unplotted: { buckets: number; count: number }
    } | null
    stats: {
      total: number
      comparison_total: number | null
      peak: { count: number; keys: string[] } | null
      empty_buckets: number
    }
  }
  locations: {
    problems_total: number
    located: number
    by_type: TypeRow[]
    ranking: RankingRow[]
    rooms_with_problems: number
    others: { rooms: number; count: number } | null
  } | null
  summary: {
    sentences: Array<{ key: string; text: string }>
    caveats: string[]
  }
}

export interface ReportPeriodMeta {
  preset: string
  label: string
  range_label: string
  from: string
  to: string
  starts_at: string
  ends_at: string
  to_date: boolean
  days: number
}

export interface TicketOverviewMeta {
  report_type: 'ticket_overview'
  timezone: string
  timezone_label: string
  generated_at: string
  as_of: string
  as_of_label: string
  today: string
  period: ReportPeriodMeta
  comparison:
    | (ReportPeriodMeta & {
        mode: 'previous' | 'last_year'
        description: string
        available: boolean
        reason: 'partial_records' | 'before_records' | 'status_filter' | null
      })
    | null
  granularity: { requested: string; resolved: string; max_buckets: number }
  filters: {
    room_type: { value: string; label: string } | null
    building: { id: string; name: string; archived: boolean; inactive: boolean } | null
    room: { id: string; label: string; archived: boolean; inactive: boolean } | null
    status: Array<{ slug: string; label: string }>
    category: Array<{ slug: string; label: string }>
    problem_area: Array<{ value: string; label: string }>
  }
  query: Record<string, string>
  data_starts_at: string | null
  data_quality: { tickets_without_history: number }
  definitions: Array<{ term: string; text: string }>
  visible: { locations: boolean }
}

export interface TicketOverview {
  data: TicketOverviewData
  meta: TicketOverviewMeta
}

export interface Option {
  value: string
  label: string
}

export interface ReportOptions {
  timezone: string
  timezone_label: string
  today: string
  data_starts_at: string | null
  since_year: { min: number; max: number }
  periods: Option[]
  granularities: Array<Option & { max_buckets: number | null }>
  comparisons: Option[]
  statuses: Array<Option & { is_open: boolean; is_terminal: boolean }>
  categories: Array<Option & { problem_area: string; is_active: boolean }>
  problem_areas: Option[]
  location_types: Option[]
  buildings: Array<{ id: string; name: string; code: string; archived: boolean; inactive: boolean }>
  rooms: Array<{
    id: string
    name: string
    code: string
    room_type: string
    label: string
    building_id: string | null
    archived: boolean
    inactive: boolean
  }>
}

/** The report filter vocabulary, as it travels in the URL and to the API (1:1). */
export interface ReportParams {
  period?: string
  anchor?: string
  from?: string
  to?: string
  since_year?: string
  granularity?: string
  compare?: string
  room_type?: string
  building?: string
  room?: string
  status?: string
  category?: string
  problem_area?: string
}

/* ------------------------------------------------------------------ documents */

/** Reports that can become a document (export, email, print). */
export type ReportType = 'ticket_overview' | 'overall'

export type ExportFormat = 'pdf' | 'docx' | 'xlsx' | 'csv'

export interface DocumentOptions {
  paper?: 'a4' | 'letter'
  orientation?: 'portrait' | 'landscape'
  include_records?: boolean
  /** Overall report: the AI interpretation, only if one exists for exactly these figures. */
  include_narrative?: boolean
}

export interface EmailDelivery {
  recipient: string
  external: boolean
  status: 'queued' | 'sent' | 'failed'
  status_label: string
  sent_at: string | null
}

/** A queued report document, as its requester sees it. */
export interface ReportExport {
  id: string
  report_type: ReportType
  report_label: string
  format: ExportFormat
  format_label: string
  purpose: 'download' | 'email'
  profile: 'full' | 'identifiers'
  status: 'pending' | 'processing' | 'completed' | 'failed' | 'expired'
  status_label: string
  filename: string | null
  size_bytes: number | null
  row_count: number | null
  truncated: boolean
  error_code: string | null
  created_at: string | null
  completed_at: string | null
  expires_at: string | null
  download_url: string | null
  deliveries?: EmailDelivery[]
}

/** The server's answer to "who would receive this, and what would they get". */
export interface RecipientCheck {
  recipients: Array<{ address: string; external: boolean }>
  external: string[]
  internal_domains: string[]
  delivers_externally: boolean
  daily_cap: number
  sent_today: number
  remaining_today: number
  contents: { report: string; format: string; includes: string[]; excludes: string[] }
}

export interface DocumentTable {
  key: string
  title: string
  columns: Array<{ key: string; label: string; type: 'text' | 'number' | 'percent' | 'code' }>
  rows: Array<Array<string | number | null>>
  total_rows: number | null
  truncated: boolean
  truncation_note: string | null
  note: string | null
  is_records: boolean
}

export interface DocumentChart {
  kind: 'line' | 'donut' | 'bar'
  title: string
  description: string
  table_key: string | null
  /** The same SVG the PDF embeds, as a `data:` image; null when there is nothing to draw. */
  image?: string | null
}

export interface DocumentSection {
  key: string
  title: string
  intro: string | null
  kpis: Array<{ label: string; value: string; detail: string | null }>
  charts: DocumentChart[]
  tables: DocumentTable[]
  paragraphs: string[]
  notes: string[]
}

/** A report document, format-independent (SDD DD-91). */
export interface ReportDocument {
  type: ReportType
  title: string
  organisation: string
  period_label: string
  comparison_label: string | null
  timezone_label: string
  as_of_label: string
  generated_label: string
  filters: Array<{ label: string; value: string }>
  summary: string[]
  summary_label: string
  caveats: string[]
  sections: DocumentSection[]
  definitions: Array<{ term: string; definition: string }>
  limitations: string[]
  profile: 'full' | 'identifiers'
  records_included: boolean
}

/* ------------------------------------------------------------ overall reports */

export type OverallSectionKey =
  'overview' | 'tickets' | 'equipment' | 'repairs' | 'locations' | 'insights'

export interface SectionKpi {
  label: string
  value: string
  detail: string | null
  drilldown: Drilldown | null
  figure: number | null
}

export interface SectionChart {
  kind: 'line' | 'donut' | 'bar'
  title: string
  description: string
  categories: string[]
  series: Array<{
    name: string
    values: Array<number | null>
    style: 'current' | 'compare' | 'slices'
  }>
  colors: string[]
  keys: string[]
  table_key: string | null
  value_label: string
}

export interface SectionTable extends DocumentTable {
  links: Array<Drilldown | null> | null
}

/** One Overall Reports section, as the server builds it (also its document section). */
export interface OverallSection {
  key: OverallSectionKey
  title: string
  intro: string | null
  kpis: SectionKpi[]
  charts: SectionChart[]
  tables: SectionTable[]
  paragraphs: string[]
  notes: string[]
  insufficient: string | null
  records: Array<{ table: string; title: string; total: number }>
  not_applicable: string[]
}

export interface OverallSectionResponse {
  data: OverallSection
  meta: {
    report_type: 'overall'
    timezone: string
    generated_at: string
    as_of_label: string
    period: { range_label: string; from: string; to: string }
    comparison: { range_label: string } | null
    sections: Array<{ key: OverallSectionKey; label: string }>
    query: Record<string, string>
    definitions: Array<{ term: string; text: string }>
  }
}

export interface RecordsPage {
  data: {
    table: string
    title: string
    columns: Array<{ key: string; label: string; type: 'text' | 'number' | 'percent' | 'code' }>
    rows: Array<Array<string | number | null>>
    links: Array<Drilldown | null>
  }
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
    sort: string
    direction: 'asc' | 'desc'
    sorts: string[]
  }
}

export interface EquipmentOption {
  value: string
  label: string
  description: string
  group: string
}

/** The Overall Reports filters beyond the shared vocabulary (1:1 with the API). */
export interface OverallParams extends ReportParams {
  equipment?: string
  maintenance_class?: string
  component_type?: string
  location_group?: string
}

/* -------------------------------------------------------------- AI insights */

export interface NarrativeFact {
  key: string
  question: string
  label: string
  value: number | string
  unit: string | null
  basis: string
  record_count: number
}

export interface NarrativeFinding {
  question: string
  kind: 'observation' | 'possible_explanation' | 'recommendation'
  text: string
  cited_fact_keys: string[]
  confidence: 'low' | 'medium' | 'high'
}

export interface ReportNarrative {
  id: string
  status: 'pending' | 'completed' | 'insufficient' | 'unavailable' | 'failed' | 'disabled'
  status_label: string
  model: string | null
  coverage: Record<string, string> | null
  findings: NarrativeFinding[]
  limitations: string[]
  withheld_count: number
  error_code: string | null
  generated_at: string | null
  created_at: string | null
}

/** GET /admin/reports/narratives/current — the facts always, the interpretation if any. */
export interface NarrativeState {
  enabled: boolean
  configured: boolean
  current: boolean
  changed_fact_keys: string[]
  facts: {
    scope: string
    questions: Record<
      string,
      { question: string; sufficient: boolean; reason: string | null; facts: NarrativeFact[] }
    >
    limitations: string[]
  }
  narrative: ReportNarrative | null
}
