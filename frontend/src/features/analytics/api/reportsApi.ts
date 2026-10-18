import { isAxiosError } from 'axios'
import { readBlobError, triggerBlobDownload } from '@/lib/download'
import { api } from '@/services/api'
import type {
  DocumentOptions,
  ExportFormat,
  RecipientCheck,
  ReportDocument,
  ReportExport,
  ReportOptions,
  ReportParams,
  ReportType,
  TicketOverview,
} from '../types'

/**
 * Report endpoints (Phase 2.10) — Administrator-only on the server, which
 * refuses anyone else whatever this client sends (ReportsAccess).
 */
export async function fetchTicketOverview(params: ReportParams): Promise<TicketOverview> {
  const { data } = await api.get<TicketOverview>('/admin/reports/ticket-overview', { params })
  return data
}

export async function fetchReportOptions(): Promise<ReportOptions> {
  const { data } = await api.get<{ data: ReportOptions }>('/admin/reports/options')
  return data.data
}

/** What a document request carries: the report's own filters, flattened, plus what to build. */
export interface DocumentRequest extends DocumentOptions {
  report_type: ReportType
  params: ReportParams
}

const documentBody = ({ report_type, params, ...options }: DocumentRequest) => ({
  report_type,
  ...params,
  ...options,
})

/** Queue a document; the server answers at once and renders on its bulk worker. */
export async function createExport(
  request: DocumentRequest & { format: ExportFormat },
): Promise<ReportExport> {
  const { data } = await api.post<{ data: ReportExport }>('/admin/reports/exports', {
    ...documentBody(request),
    format: request.format,
  })
  return data.data
}

export async function fetchExport(id: string): Promise<ReportExport> {
  const { data } = await api.get<{ data: ReportExport }>(`/admin/reports/exports/${id}`)
  return data.data
}

export async function fetchRecentExports(): Promise<ReportExport[]> {
  const { data } = await api.get<{ data: ReportExport[] }>('/admin/reports/exports')
  return data.data
}

/**
 * Fetch a finished document and save it. A 409 (still preparing) or 410
 * (expired) arrives as a blob; its message is surfaced in the server's words.
 */
export async function downloadExport(item: ReportExport): Promise<void> {
  try {
    const response = await api.get(`/admin/reports/exports/${item.id}/download`, {
      responseType: 'blob',
    })
    triggerBlobDownload(
      response.data as Blob,
      String(response.headers['content-disposition'] ?? ''),
      item.filename ?? `report.${item.format}`,
    )
  } catch (error) {
    if (isAxiosError(error)) {
      const message = await readBlobError(error.response?.data)
      if (message) throw new Error(message, { cause: error })
    }
    throw error
  }
}

export async function checkRecipients(
  request: DocumentRequest & { format: ExportFormat; recipients: string[] },
): Promise<RecipientCheck> {
  const { data } = await api.post<{ data: RecipientCheck }>(
    '/admin/reports/emails/check-recipients',
    { ...documentBody(request), format: request.format, recipients: request.recipients },
  )
  return data.data
}

export async function sendReportEmail(
  request: DocumentRequest & {
    format: ExportFormat
    recipients: string[]
    confirmed_external: string[]
  },
): Promise<ReportExport> {
  const { data } = await api.post<{ data: ReportExport }>('/admin/reports/emails', {
    ...documentBody(request),
    format: request.format,
    recipients: request.recipients,
    confirmed_external: request.confirmed_external,
  })
  return data.data
}

/** The printable document — the same content a PDF carries. */
export async function fetchPrintDocument(request: DocumentRequest): Promise<ReportDocument> {
  const { data } = await api.post<{ data: ReportDocument }>(
    '/admin/reports/print',
    documentBody(request),
  )
  return data.data
}
