/**
 * Client mirrors of the Asset Management API contract (SRS FR-AST-*, FR-PC-*,
 * FR-QR-*). Every `id` is a uuid — numeric keys never leave the server.
 */

/** Mirrors the backend `AssetStatus` enum (varchar + CHECK domain). */
export type AssetStatusValue =
  | 'new'
  | 'in_stock'
  | 'reserved'
  | 'deployed'
  | 'in_repair'
  | 'out_of_service'
  | 'in_transit'
  | 'retired'
  | 'disposed'

/** Mirrors the backend `PcStatus` enum. */
export type PcStatusValue =
  'available' | 'assigned' | 'online' | 'offline' | 'under_maintenance' | 'retired'

export type ConditionValue = 'working' | 'faulty' | 'for_repair' | 'decommissioned'

/** Presentation tone the server decides, so the client never picks a status colour. */
export type Tone = 'neutral' | 'success' | 'warning' | 'danger'

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

export interface Detail<T> {
  data: T
  meta?: AssetMeta
  message?: string
}

export interface Blockers {
  installed?: number
  components?: number
  open_tickets?: number
}

export interface StatusTransition {
  value: AssetStatusValue
  label: string
  tone: Tone
  terminal: boolean
}

export interface AssetMeta {
  blockers?: Blockers
  in_use?: boolean
  transitions?: StatusTransition[]
}

/** The 422 payload raised when an archive would strand something. */
export interface AssetInUseError {
  message: string
  code: 'asset_in_use'
  level: 'asset' | 'pc_unit'
  blockers: Blockers
  installed_in?: { id: string; name: string; unit_code: string }
  pc_unit?: { id: string; name: string; unit_code: string }
}

export interface RoomRef {
  id: string
  name: string
  code?: string | null
  archived?: boolean
}

export interface BuildingRef {
  id: string
  name: string
  code?: string | null
}

export interface FloorRef {
  id: string
  name: string
  floor_number: number
}

export interface TechnicianRef {
  id: string
  name: string
  email?: string
}

export interface AssetListItem {
  id: string
  asset_tag: string
  name: string
  serial_number: string | null
  barcode: string | null
  status: AssetStatusValue
  status_label: string
  tone: Tone
  condition: ConditionValue
  condition_label: string
  category: string | null
  category_label: string | null
  manufacturer: string | null
  model: string | null
  supplier?: string | null
  room: RoomRef | null
  building: BuildingRef | null
  technician: TechnicianRef | null
  warranty_expiration: string | null
  warranty_days_remaining: number | null
  under_warranty: boolean
  created_at: string | null
  updated_at: string | null
  archived: boolean
}

export interface AssetAttachment {
  id: string
  kind: 'image' | 'document'
  is_image: boolean
  filename: string
  mime_type: string | null
  file_size: number | null
  caption: string | null
  uploaded_by: string | null
  uploaded_at: string | null
  url: string
}

export interface QrCodeItem {
  id: string
  code: string
  payload: string | null
  location_label: string | null
  status: 'active' | 'inactive' | 'revoked'
  status_label: string
  is_active: boolean
  generated_at: string | null
  last_scanned_at: string | null
  target_type: 'asset' | 'pc_unit'
}

export interface InstallationItem {
  id: string
  installation_status: string
  installation_status_label: string
  installed_at: string | null
  removed_at: string | null
  current: boolean
  remarks: string | null
  installed_by: string | null
  asset: {
    id: string
    asset_tag: string
    name: string
    category: string | null
    category_label: string | null
    archived: boolean
  } | null
  pc_unit: { id: string; unit_code: string; pc_name: string; archived: boolean } | null
}

export interface TransferItem {
  id: string
  from: { id: string; name: string; label: string; archived: boolean } | null
  to: { id: string; name: string; label: string; archived: boolean } | null
  reason: string | null
  remarks: string | null
  transferred_by: string | null
  transferred_at: string | null
}

export interface MaintenanceItem {
  id: string
  title: string
  type: string | null
  status: string
  status_label: string
  diagnosis: string | null
  root_cause: string | null
  resolution: string | null
  downtime_minutes: number | null
  labor_hours: string | null
  cost: string | null
  technician: string | null
  scheduled_for: string | null
  started_at: string | null
  completed_at: string | null
  maintenance_date: string | null
}

export interface AssetDetail {
  id: string
  asset_tag: string
  name: string | null
  display_name: string
  serial_number: string | null
  barcode: string | null
  notes: string | null
  status: AssetStatusValue
  status_label: string
  tone: Tone
  terminal: boolean
  condition: ConditionValue
  condition_label: string
  category: string | null
  category_label: string | null
  category_group: string | null
  manufacturer: string | null
  component: string | null
  model: { id: number; name: string; number: string | null; specifications: unknown } | null
  supplier: {
    name: string
    contact_person: string | null
    contact_number: string | null
    email: string | null
  } | null
  purchase: { price: string | null; date: string | null }
  warranty: {
    expiration: string | null
    days_remaining: number | null
    under_warranty: boolean
    supplier: string | null
    purchase_date: string | null
  }
  room: RoomRef | null
  floor: FloorRef | null
  building: BuildingRef | null
  technician: TechnicianRef | null
  installations?: InstallationItem[]
  transfers?: TransferItem[]
  attachments?: AssetAttachment[]
  qr_codes?: QrCodeItem[]
  maintenance?: MaintenanceItem[]
  created_by: string | null
  updated_by: string | null
  created_at: string | null
  updated_at: string | null
  archived: boolean
  archived_at: string | null
}

export interface PcSpecification {
  cpu: string | null
  motherboard: string | null
  ram: string | null
  gpu: string | null
  storage_primary: string | null
  storage_secondary: string | null
  power_supply: string | null
  monitor: string | null
  keyboard: string | null
  mouse: string | null
  operating_system: string | null
  bios_version: string | null
  network_adapter: string | null
  updated_at?: string | null
}

export interface PcUnitListItem {
  id: string
  unit_code: string
  pc_name: string
  asset_tag: string | null
  hostname: string | null
  serial_number: string | null
  brand: string | null
  model: string | null
  status: PcStatusValue
  status_label: string
  condition: ConditionValue
  condition_label: string
  room: RoomRef | null
  building: BuildingRef | null
  components_count: number
  tickets_count: number
  warranty_expiration: string | null
  warranty_days_remaining: number | null
  qr_identifier: string | null
  created_at: string | null
  updated_at: string | null
  archived: boolean
}

export interface PcUnitDetail extends Omit<PcUnitListItem, 'components_count' | 'tickets_count'> {
  notes: string | null
  network: { ip_address: string | null; mac_address: string | null }
  purchase: { date: string | null }
  warranty: {
    expiration: string | null
    days_remaining: number | null
    under_warranty: boolean
    purchase_date: string | null
  }
  floor: FloorRef | null
  specification: PcSpecification | null
  installations?: InstallationItem[]
  attachments?: AssetAttachment[]
  qr_codes?: QrCodeItem[]
  maintenance?: MaintenanceItem[]
  created_by: string | null
  updated_by: string | null
  archived_at: string | null
}

/** One entry in the merged asset timeline. */
export interface HistoryEntry {
  id: string
  type: 'activity' | 'status' | 'transfer' | 'installation' | 'maintenance' | 'ticket' | 'qr'
  action: string
  label: string
  description: string | null
  actor: { id: string; name: string } | null
  properties: Record<string, unknown> | null
  at: string | null
}

/** The history endpoint returns a raw paginator, not a resource envelope. */
export interface HistoryPage {
  data: HistoryEntry[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface OptionItem {
  value: string
  label: string
}

export interface CategoryOption extends OptionItem {
  group: 'equipment' | 'peripheral' | 'component'
}

export interface StatusOption extends OptionItem {
  tone: Tone
  terminal: boolean
}

export interface ModelOption {
  value: number
  label: string
  model_number: string | null
  category: string | null
  category_label: string | null
  component: string | null
  manufacturer: string | null
  specifications: unknown
}

export interface TechnicianOption extends OptionItem {
  role: string
}

export interface AssetCatalog {
  statuses: StatusOption[]
  conditions: OptionItem[]
  categories: CategoryOption[]
  manufacturers: OptionItem[]
  suppliers: OptionItem[]
  models: ModelOption[]
  technicians: TechnicianOption[]
  pc_statuses: OptionItem[]
}

export interface DistributionRow {
  key: string | null
  label: string
  count: number
}

export interface AssetDashboard {
  summary: {
    total: number
    new: number
    available: number
    assigned: number
    in_service: number
    maintenance: number
    out_of_service: number
    in_transit: number
    retired: number
    disposed: number
    archived: number
    unassigned_location: number
  }
  by_status: DistributionRow[]
  by_building: DistributionRow[]
  by_room: DistributionRow[]
  by_category: DistributionRow[]
  warranty_expiring: {
    count: number
    days: number
    items: Array<{
      id: string
      asset_tag: string
      name: string
      room: string | null
      warranty_expiration: string | null
      days_remaining: number | null
    }>
  }
  recently_added: RecentAsset[]
  recently_updated: RecentAsset[]
  pc_units: { by_status: DistributionRow[] }
  filters: {
    statuses: StatusOption[]
    conditions: OptionItem[]
    categories: CategoryOption[]
  }
  generated_at: string
}

export interface RecentAsset {
  id: string
  asset_tag: string
  name: string
  status: AssetStatusValue
  status_label: string
  tone: Tone
  room: string | null
  at: string | null
}

export type TrashedFilter = 'without' | 'with' | 'only'

export type AssetSortColumn =
  | 'asset_tag'
  | 'name'
  | 'status'
  | 'condition'
  | 'serial_number'
  | 'purchase_date'
  | 'warranty_expiration'
  | 'created_at'
  | 'updated_at'
  | 'model'
  | 'category'
  | 'room'
  | 'building'
  | 'supplier'
  | 'technician'

export type PcSortColumn =
  | 'unit_code'
  | 'pc_name'
  | 'asset_tag'
  | 'hostname'
  | 'status'
  | 'current_condition'
  | 'purchase_date'
  | 'warranty_expiration'
  | 'created_at'
  | 'updated_at'
  | 'room'
  | 'building'

export interface AssetParams {
  search?: string
  status?: string
  condition?: string
  category?: string
  manufacturer?: string
  supplier?: string
  technician?: string
  building?: string
  floor?: string
  room?: string
  warranty_expiring?: number
  trashed?: TrashedFilter
  sort?: AssetSortColumn
  direction?: 'asc' | 'desc'
  per_page?: number
  page?: number
}

export interface PcUnitParams {
  search?: string
  status?: string
  condition?: string
  building?: string
  floor?: string
  room?: string
  warranty_expiring?: number
  trashed?: TrashedFilter
  sort?: PcSortColumn
  direction?: 'asc' | 'desc'
  per_page?: number
  page?: number
}
