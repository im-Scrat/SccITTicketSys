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
