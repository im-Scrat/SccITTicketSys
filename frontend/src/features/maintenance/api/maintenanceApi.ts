import { api } from '@/services/api'
import type { ActivityEntry } from '@/types/activity'
import type {
  ChecklistItem,
  EvidenceItem,
  EvidenceStage,
  HardwareReplacement,
  MaintenanceDashboard,
  MaintenanceDetail,
  MaintenanceDetailEnvelope,
  MaintenanceListItem,
  MaintenanceNote,
  MaintenanceOptions,
  MaintenanceParams,
  Paginated,
} from '../types'

/** Drop empty values so they never reach the query string as `?search=`. */
function cleanParams(params: object): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[key] = value as string | number
  }
  return out
}

/* ---------------------------------------------------------------- reads */

export async function fetchMaintenanceOptions(): Promise<MaintenanceOptions> {
  const { data } = await api.get<{ data: MaintenanceOptions }>('/maintenance/options')
  return data.data
}

/** A technician's open work. Scoped server-side; no parameter widens it. */
export async function fetchMaintenanceQueue(
  params: MaintenanceParams,
): Promise<Paginated<MaintenanceListItem>> {
  const { data } = await api.get<Paginated<MaintenanceListItem>>('/maintenance', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchMaintenanceHistory(
  params: MaintenanceParams,
): Promise<Paginated<MaintenanceListItem>> {
  const { data } = await api.get<Paginated<MaintenanceListItem>>('/maintenance/history', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchScheduledMaintenance(
  params: MaintenanceParams,
): Promise<Paginated<MaintenanceListItem>> {
  const { data } = await api.get<Paginated<MaintenanceListItem>>('/maintenance/scheduled', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchMaintenanceRecord(id: string): Promise<MaintenanceDetail> {
  const { data } = await api.get<MaintenanceDetailEnvelope>(`/maintenance/${id}`)
  return data.data
}

export async function fetchMaintenanceAudit(
  id: string,
  page = 1,
): Promise<Paginated<ActivityEntry>> {
  const { data } = await api.get<Paginated<ActivityEntry>>(`/maintenance/${id}/audit`, {
    params: { page },
  })
  return data
}

export async function fetchMaintenanceDirectory(
  params: MaintenanceParams,
): Promise<Paginated<MaintenanceListItem>> {
  const { data } = await api.get<Paginated<MaintenanceListItem>>('/admin/maintenance', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchMaintenanceDashboard(): Promise<MaintenanceDashboard> {
  const { data } = await api.get<{ data: MaintenanceDashboard }>('/admin/maintenance/dashboard')
  return data.data
}

/* --------------------------------------------------------------- writes */

export interface MaintenancePayload {
  title: string
  type: string
  pc_unit?: string | null
  asset?: string | null
  ticket?: string | null
  technician?: string | null
  scheduled_for?: string | null
  diagnosis?: string | null
  root_cause?: string | null
  resolution?: string | null
  preventive_recommendation?: string | null
  downtime_minutes?: number | null
  labor_hours?: number | null
  cost?: number | null
}

/**
 * Open a record.
 *
 * Returns the envelope rather than just the record: `meta.concurrent` names any
 * open maintenance already targeting the same machine, which the caller shows as
 * a warning. It is deliberately not an error — a machine may legitimately carry
 * a scheduled preventive visit and an active corrective repair at once.
 */
export async function createMaintenance(
  payload: MaintenancePayload,
): Promise<MaintenanceDetailEnvelope> {
  const { data } = await api.post<MaintenanceDetailEnvelope>('/maintenance', payload)
  return data
}

export async function updateMaintenance(
  id: string,
  payload: Partial<MaintenancePayload> & { reschedule_reason?: string },
): Promise<MaintenanceDetail> {
  const { data } = await api.put<MaintenanceDetailEnvelope>(`/maintenance/${id}`, payload)
  return data.data
}

export async function changeMaintenanceStatus(
  id: string,
  status: string,
  extra: { resolution?: string; reason?: string } = {},
): Promise<MaintenanceDetail> {
  const { data } = await api.put<MaintenanceDetailEnvelope>(`/maintenance/${id}/status`, {
    status,
    ...extra,
  })
  return data.data
}

export async function reassignMaintenance(
  id: string,
  technician: string,
): Promise<MaintenanceDetail> {
  const { data } = await api.post<MaintenanceDetailEnvelope>(`/maintenance/${id}/reassign`, {
    technician,
  })
  return data.data
}

export async function archiveMaintenance(id: string): Promise<void> {
  await api.delete(`/maintenance/${id}`)
}

export async function restoreMaintenance(id: string): Promise<MaintenanceDetail> {
  const { data } = await api.post<MaintenanceDetailEnvelope>(`/maintenance/${id}/restore`)
  return data.data
}

/* ------------------------------------------------------------- children */

export async function toggleChecklistItem(
  recordId: string,
  itemId: number,
  isCompleted: boolean,
  remarks?: string,
): Promise<ChecklistItem> {
  const { data } = await api.put<{ data: ChecklistItem }>(
    `/maintenance/${recordId}/checklist/${itemId}`,
    { is_completed: isCompleted, remarks },
  )
  return data.data
}

export async function uploadEvidence(
  recordId: string,
  file: File,
  imageType: EvidenceStage,
  caption?: string,
): Promise<EvidenceItem> {
  const form = new FormData()
  form.append('file', file)
  form.append('image_type', imageType)
  if (caption) form.append('caption', caption)

  const { data } = await api.post<{ data: EvidenceItem }>(
    `/maintenance/${recordId}/evidence`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return data.data
}

export async function deleteEvidence(recordId: string, imageId: string): Promise<void> {
  await api.delete(`/maintenance/${recordId}/evidence/${imageId}`)
}

/**
 * Fetch one evidence item as a blob.
 *
 * The SPA builds its own object URL from these bytes rather than pointing an
 * `<img src>` at the route, which is what lets the server force
 * `Content-Disposition: attachment` on documents without breaking image
 * previews (SDD DD-45). It is also why no storage path is ever exposed: the
 * only way to the file is this authorized request.
 */
export async function fetchEvidenceBlob(recordId: string, imageId: string): Promise<Blob> {
  const { data } = await api.get<Blob>(`/maintenance/${recordId}/evidence/${imageId}`, {
    responseType: 'blob',
  })
  return data
}

export async function addMaintenanceNote(recordId: string, body: string): Promise<MaintenanceNote> {
  const { data } = await api.post<{ data: MaintenanceNote }>(`/maintenance/${recordId}/notes`, {
    body,
  })
  return data.data
}

export interface ReplacementPayload {
  old_component?: number | null
  new_component?: number | null
  old_asset?: string | null
  new_asset?: string | null
  quantity: number
  reason?: string | null
  warranty_months?: number | null
}

export async function recordReplacement(
  recordId: string,
  payload: ReplacementPayload,
): Promise<HardwareReplacement> {
  const { data } = await api.post<{ data: HardwareReplacement }>(
    `/maintenance/${recordId}/replacements`,
    payload,
  )
  return data.data
}
