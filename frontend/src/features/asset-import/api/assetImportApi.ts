import { readBlobError, triggerBlobDownload } from '@/lib/download'
import { api } from '@/services/api'
import type {
  AssetImport,
  AssetImportListItem,
  CatalogEntry,
  ImportLocation,
  ImportReview,
  ImportMode,
  ImportRow,
  MappingPayload,
  MeaningTable,
  Paginated,
  RoomOption,
} from '../types'

/**
 * Asset import endpoints (Phase 2.10 R6) — Administrator-only on the server
 * (AssetImportAccess), which refuses anyone else whatever this client sends.
 */
const base = '/admin/asset-imports'

export async function fetchImports(page = 1): Promise<Paginated<AssetImportListItem>> {
  const { data } = await api.get<Paginated<AssetImportListItem>>(base, { params: { page } })
  return data
}

export async function fetchImport(id: string): Promise<AssetImport> {
  const { data } = await api.get<{ data: AssetImport }>(`${base}/${id}`)
  return data.data
}

export async function uploadImport(
  file: File,
  mode: ImportMode,
  onProgress?: (fraction: number) => void,
): Promise<AssetImport> {
  const form = new FormData()
  form.append('file', file)
  form.append('entity_mode', mode)
  const { data } = await api.post<{ data: AssetImport }>(base, form, {
    onUploadProgress: (event) => {
      if (onProgress && event.total) onProgress(event.loaded / event.total)
    },
  })
  return data.data
}

export interface SourceOptions {
  sheet?: string | null
  header_row?: number | null
  delimiter?: string | null
  encoding?: string | null
}

export async function updateSource(id: string, options: SourceOptions): Promise<AssetImport> {
  const { data } = await api.put<{ data: AssetImport }>(`${base}/${id}/source`, options)
  return data.data
}

export async function updateMapping(id: string, payload: MappingPayload): Promise<AssetImport> {
  const { data } = await api.put<{ data: AssetImport }>(`${base}/${id}/mapping`, payload)
  return data.data
}

export async function fetchMeanings(
  id: string,
  column: number,
  target: string,
): Promise<MeaningTable[]> {
  const { data } = await api.get<{ data: MeaningTable[] }>(`${base}/${id}/values`, {
    params: { column, target },
  })
  return data.data
}

export interface RowQuery {
  status?: string
  type?: string
  search?: string
  page?: number
  per_page?: number
  outcome?: string
}

export async function fetchRows(id: string, query: RowQuery): Promise<Paginated<ImportRow>> {
  const { data } = await api.get<Paginated<ImportRow>>(`${base}/${id}/rows`, { params: query })
  return data
}

export interface RowChange {
  corrections?: Record<string, unknown>
  acknowledge?: string[]
  exclude?: boolean
}

export async function updateRow(
  id: string,
  rowNumber: number,
  change: RowChange,
): Promise<{ data: ImportRow; import: AssetImport }> {
  const { data } = await api.patch<{ data: ImportRow; import: AssetImport }>(
    `${base}/${id}/rows/${rowNumber}`,
    change,
  )
  return data
}

export async function bulkRows(
  id: string,
  rows: number[],
  exclude: boolean,
): Promise<{ updated: number; import: AssetImport }> {
  const { data } = await api.post<{ updated: number; import: AssetImport }>(
    `${base}/${id}/rows/bulk`,
    {
      rows,
      exclude,
    },
  )
  return data
}

export async function fetchLocations(id: string): Promise<ImportLocation[]> {
  const { data } = await api.get<{ data: ImportLocation[] }>(`${base}/${id}/locations`)
  return data.data
}

export interface LocationDecision {
  resolution: 'pending' | 'room' | 'unassigned'
  room?: string | null
  assign_anyway?: boolean
}

export async function decideLocation(
  id: string,
  location: string,
  decision: LocationDecision,
): Promise<{ data: ImportLocation; import: AssetImport }> {
  const { data } = await api.put<{ data: ImportLocation; import: AssetImport }>(
    `${base}/${id}/locations/${location}`,
    decision,
  )
  return data
}

export async function fetchCatalog(id: string): Promise<CatalogEntry[]> {
  const { data } = await api.get<{ data: CatalogEntry[] }>(`${base}/${id}/catalog`)
  return data.data
}

export interface CatalogDecision {
  decision: 'pending' | 'approved' | 'existing' | 'rejected'
  model?: number | null
  manufacturer_name?: string | null
  model_name?: string | null
  model_number?: string | null
}

export async function decideCatalog(
  id: string,
  entry: string,
  decision: CatalogDecision,
): Promise<{ data: CatalogEntry; import: AssetImport }> {
  const { data } = await api.put<{ data: CatalogEntry; import: AssetImport }>(
    `${base}/${id}/catalog/${entry}`,
    decision,
  )
  return data
}

export async function confirmImport(
  id: string,
  revision: number,
  expected: { pc_units: number; assets: number; catalog_entries: number },
): Promise<AssetImport> {
  const { data } = await api.post<{ data: AssetImport }>(`${base}/${id}/confirm`, {
    revision,
    expected,
  })
  return data.data
}

export async function cancelImport(id: string): Promise<AssetImport> {
  const { data } = await api.post<{ data: AssetImport }>(`${base}/${id}/cancel`)
  return data.data
}

export async function retryImport(id: string): Promise<AssetImport> {
  const { data } = await api.post<{ data: AssetImport }>(`${base}/${id}/retry`)
  return data.data
}

export async function fetchRoomOptions(search: string): Promise<RoomOption[]> {
  const { data } = await api.get<{ data: RoomOption[] }>(`${base}/room-options`, {
    params: { search },
  })
  return data.data
}

async function download(url: string, fallback: string): Promise<void> {
  try {
    const response = await api.get<Blob>(url, { responseType: 'blob' })
    triggerBlobDownload(
      response.data,
      String(response.headers['content-disposition'] ?? ''),
      fallback,
    )
  } catch (error) {
    const message = await readBlobError((error as { response?: { data?: unknown } }).response?.data)
    throw new Error(message ?? 'The file could not be downloaded.', { cause: error })
  }
}

export function downloadTemplate(mode: ImportMode): Promise<void> {
  return download(`${base}/template?mode=${mode}`, `sccit-import-template-${mode}.xlsx`)
}

export function downloadOriginal(id: string, extension: string): Promise<void> {
  return download(`${base}/${id}/file`, `asset-import-${id}.${extension}`)
}

export async function fetchReview(id: string): Promise<ImportReview> {
  const { data } = await api.get<{ data: ImportReview }>(`${base}/${id}/review`)
  return data.data
}

export async function acknowledgeReview(id: string): Promise<ImportReview['receipt']> {
  const { data } = await api.post<{ data: ImportReview['receipt'] }>(
    `${base}/${id}/review/acknowledge`,
  )
  return data.data
}
