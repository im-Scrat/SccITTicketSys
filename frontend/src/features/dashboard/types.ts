/** Tone maps onto the reserved status palette — state only, never identity. */
export type WidgetTone = 'warning' | 'danger' | null

export interface KpiItem {
  key: string
  label: string
  value: number
  tone?: WidgetTone
  href?: string
}

export interface DistributionRow {
  key: string
  label: string
  count: number
}

export interface ListRow {
  id: string | null
  cells: Array<string | number | null>
  /** Index of a cell holding an ISO timestamp, so it can be humanised. */
  timestamp_cell?: number
}

export interface AnnouncementRow {
  id: string
  title: string
  content: string
  pinned: boolean
  at: string | null
}

export interface ActionItem {
  key: string
  label: string
  description?: string
  href: string
  primary?: boolean
  /** False while the target module has not shipped yet. */
  available?: boolean
}

interface BaseWidget {
  key: string
  title: string
  description?: string
  /** Optional link to the module this widget summarises. */
  href?: string
}

export interface KpiWidget extends BaseWidget {
  type: 'kpi'
  items: KpiItem[]
}

export interface DistributionWidget extends BaseWidget {
  type: 'distribution'
  unit?: string
  empty?: string
  rows: DistributionRow[]
}

export interface ListWidget extends BaseWidget {
  type: 'list'
  columns: string[]
  empty?: string
  rows: ListRow[]
}

export interface ActionsWidget extends BaseWidget {
  type: 'actions'
  items: ActionItem[]
}

export interface AnnouncementsWidget extends BaseWidget {
  type: 'announcements'
  rows: AnnouncementRow[]
}

export type DashboardWidget =
  KpiWidget | DistributionWidget | ListWidget | ActionsWidget | AnnouncementsWidget

export interface DashboardPayload {
  role: 'administrator' | 'technician' | 'teacher' | string
  generated_at: string
  widgets: DashboardWidget[]
}
