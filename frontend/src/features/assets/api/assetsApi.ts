import { api } from '@/services/api'
import type {
  AssetAttachment,
  AssetCatalog,
  AssetDashboard,
  AssetDetail,
  AssetListItem,
  AssetParams,
  AssetStatusValue,
  Detail,
  HistoryPage,
  Paginated,
  PcSpecification,
  PcUnitDetail,
  PcUnitListItem,
  PcUnitParams,
  QrCodeItem,
} from '../types'

/** Drop empty values so they never reach the query string as `?search=`. */
function cleanParams(params: object): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[key] = value as string | number
  }
  return out
}

/** `assets` and `pc-units` share every sub-resource route shape. */
export type AssetKind = 'assets' | 'pc-units'

/* ---------------------------------------------------------------- reads */

export async function fetchAssetDashboard(): Promise<AssetDashboard> {
  const { data } = await api.get<{ data: AssetDashboard }>('/admin/assets/dashboard')
  return data.data
}

export async function fetchCatalog(): Promise<AssetCatalog> {
  const { data } = await api.get<{ data: AssetCatalog }>('/admin/assets/catalog')
  return data.data
}

export async function listAssets(params: AssetParams): Promise<Paginated<AssetListItem>> {
  const { data } = await api.get<Paginated<AssetListItem>>('/admin/assets', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchAsset(id: string): Promise<Detail<AssetDetail>> {
  const { data } = await api.get<Detail<AssetDetail>>(`/admin/assets/${id}`)
  return data
}

export async function listPcUnits(params: PcUnitParams): Promise<Paginated<PcUnitListItem>> {
  const { data } = await api.get<Paginated<PcUnitListItem>>('/admin/pc-units', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchPcUnit(id: string): Promise<Detail<PcUnitDetail>> {
  const { data } = await api.get<Detail<PcUnitDetail>>(`/admin/pc-units/${id}`)
  return data
}

export async function fetchHistory(kind: AssetKind, id: string, page = 1): Promise<HistoryPage> {
  const { data } = await api.get<HistoryPage>(`/admin/${kind}/${id}/history`, {
    params: { page },
  })
  return data
}

export interface AuditEntry {
  id: number
  action: string
  label: string
  description: string | null
  module: string | null
  actor?: { id: string; name: string } | null
  properties: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

export async function fetchAudit(
  kind: AssetKind,
  id: string,
  page = 1,
): Promise<Paginated<AuditEntry>> {
  const { data } = await api.get<Paginated<AuditEntry>>(`/admin/${kind}/${id}/audit`, {
    params: { page },
  })
  return data
}

/* --------------------------------------------------------------- writes */

export interface AssetPayload {
  asset_tag: string
  name?: string | null
  hardware_model: number
  supplier?: string | null
  room?: string | null
  technician?: string | null
  serial_number?: string | null
  barcode?: string | null
  status?: AssetStatusValue
  condition?: string
  purchase_price?: number | null
  purchase_date?: string | null
  warranty_expiration?: string | null
  notes?: string | null
}

export async function createAsset(payload: AssetPayload): Promise<AssetDetail> {
  const { data } = await api.post<Detail<AssetDetail>>('/admin/assets', payload)
  return data.data
}

export async function updateAsset(
  id: string,
  payload: Partial<AssetPayload>,
): Promise<AssetDetail> {
  const { data } = await api.put<Detail<AssetDetail>>(`/admin/assets/${id}`, payload)
  return data.data
}

/**
 * Lifecycle, transfer and assignment each have their own endpoint because each
 * writes a history row alongside the change — they are deliberately not part of
 * the plain update payload.
 */
export async function changeAssetStatus(
  id: string,
  status: AssetStatusValue,
  reason?: string,
): Promise<AssetDetail> {
  const { data } = await api.put<Detail<AssetDetail>>(`/admin/assets/${id}/status`, {
    status,
    reason,
  })
  return data.data
}

export async function transferAsset(
  id: string,
  room: string | null,
  reason?: string,
  remarks?: string,
): Promise<AssetDetail> {
  const { data } = await api.post<Detail<AssetDetail>>(`/admin/assets/${id}/transfer`, {
    room,
    reason,
    remarks,
  })
  return data.data
}

export async function assignTechnician(
  id: string,
  technician: string | null,
): Promise<AssetDetail> {
  const { data } = await api.post<Detail<AssetDetail>>(`/admin/assets/${id}/assign`, {
    technician,
  })
  return data.data
}

export async function archiveAsset(id: string): Promise<void> {
  await api.delete(`/admin/assets/${id}`)
}

export async function restoreAsset(id: string): Promise<void> {
  await api.post(`/admin/assets/${id}/restore`)
}

export interface PcUnitPayload {
  unit_code: string
  pc_name: string
  asset_tag?: string | null
  hostname?: string | null
  serial_number?: string | null
  brand?: string | null
  model?: string | null
  room?: string | null
  ip_address?: string | null
  mac_address?: string | null
  status?: string
  current_condition?: string
  purchase_date?: string | null
  warranty_expiration?: string | null
  notes?: string | null
  specification?: Partial<PcSpecification>
}

export async function createPcUnit(payload: PcUnitPayload): Promise<PcUnitDetail> {
  const { data } = await api.post<Detail<PcUnitDetail>>('/admin/pc-units', payload)
  return data.data
}

export async function updatePcUnit(
  id: string,
  payload: Partial<PcUnitPayload>,
): Promise<PcUnitDetail> {
  const { data } = await api.put<Detail<PcUnitDetail>>(`/admin/pc-units/${id}`, payload)
  return data.data
}

export async function updateSpecification(
  id: string,
  payload: Partial<PcSpecification>,
): Promise<PcSpecification> {
  const { data } = await api.put<Detail<PcSpecification>>(
    `/admin/pc-units/${id}/specification`,
    payload,
  )
  return data.data
}

export async function archivePcUnit(id: string): Promise<void> {
  await api.delete(`/admin/pc-units/${id}`)
}

export async function restorePcUnit(id: string): Promise<void> {
  await api.post(`/admin/pc-units/${id}/restore`)
}

/* ------------------------------------------------------------------- QR */

export interface QrPayload {
  data: QrCodeItem[]
  meta: {
    /**
     * The active label, **unwrapped**.
     *
     * Laravel wraps a resource in `data` only when it is the *top level* of a
     * response. `meta.active` is a resource nested inside `additional()`, so it
     * serializes as the object itself — unlike `QrPrintPayload.data`, which is
     * the top-level resource and therefore *is* wrapped. Reading
     * `meta.active.data` here silently yielded `undefined`, which the panel read
     * as "no label" and rendered its empty state over a machine that had one.
     */
    active: QrCodeItem | null
    svg: string | null
    default_size: number
    error_correction: string
  }
}

export async function fetchQrCodes(kind: AssetKind, id: string): Promise<QrPayload> {
  const { data } = await api.get<QrPayload>(`/admin/${kind}/${id}/qr`)
  return data
}

export async function generateQr(kind: AssetKind, id: string): Promise<{ svg: string }> {
  const { data } = await api.post<{ data: QrCodeItem; meta: { svg: string } }>(
    `/admin/${kind}/${id}/qr`,
  )
  return { svg: data.meta.svg }
}

export async function regenerateQr(kind: AssetKind, id: string): Promise<{ svg: string }> {
  const { data } = await api.post<{ data: QrCodeItem; meta: { svg: string } }>(
    `/admin/${kind}/${id}/qr/regenerate`,
  )
  return { svg: data.meta.svg }
}

export async function revokeQr(kind: AssetKind, id: string): Promise<void> {
  await api.post(`/admin/${kind}/${id}/qr/revoke`)
}

export interface QrPrintPayload {
  data: QrCodeItem
  meta: {
    svg: string
    label: string
    identifier: string
    location: string | null
  }
}

export async function fetchQrPrint(
  kind: AssetKind,
  id: string,
  size = 512,
): Promise<QrPrintPayload> {
  const { data } = await api.get<QrPrintPayload>(`/admin/${kind}/${id}/qr/print`, {
    params: { size },
  })
  return data
}

/* ---------------------------------------------------------- attachments */

export async function listAttachments(kind: AssetKind, id: string): Promise<AssetAttachment[]> {
  const { data } = await api.get<{ data: AssetAttachment[] }>(`/admin/${kind}/${id}/attachments`)
  return data.data
}

export async function uploadAttachment(
  kind: AssetKind,
  id: string,
  file: File,
  caption?: string,
): Promise<AssetAttachment> {
  const form = new FormData()
  form.append('file', file)
  if (caption) form.append('caption', caption)

  const { data } = await api.post<Detail<AssetAttachment>>(
    `/admin/${kind}/${id}/attachments`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return data.data
}

export async function deleteAttachment(attachmentId: string): Promise<void> {
  await api.delete(`/admin/asset-attachments/${attachmentId}`)
}

/**
 * Attachments live on a private disk, so an `<img src>` cannot fetch one
 * directly — the request has to carry the session. This pulls the bytes through
 * the same axios instance as every other call and hands back an object URL the
 * caller must revoke when done.
 */
export async function fetchAttachmentObjectUrl(attachmentId: string): Promise<string> {
  const { data } = await api.get<Blob>(`/admin/asset-attachments/${attachmentId}`, {
    responseType: 'blob',
  })
  return URL.createObjectURL(data)
}

/* -------------------------------------------------------- my (custodian) */

/**
 * Assets currently in the caller's own custody. The one asset surface a
 * Technician — or an Administrator viewing their personal custody rather than
 * the register at large — reaches without the `assets.view` permission
 * (SDD DD-38): the backend pre-scopes this to `assigned_technician_id = me`.
 */
export async function listMyAssets(page = 1): Promise<Paginated<AssetListItem>> {
  const { data } = await api.get<Paginated<AssetListItem>>('/my/assets', { params: { page } })
  return data
}

export async function fetchMyAsset(id: string): Promise<Detail<AssetDetail>> {
  const { data } = await api.get<Detail<AssetDetail>>(`/my/assets/${id}`)
  return data
}
