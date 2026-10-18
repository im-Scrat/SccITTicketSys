import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  checkRecipients,
  createExport,
  fetchExport,
  fetchRecentExports,
  sendReportEmail,
} from '../api/reportsApi'
import type { ReportExport } from '../types'

/**
 * Report documents (Phase 2.10 R3). Outside the `['dashboard', …]` prefix on
 * purpose: the live channel invalidates that on every ticket change, and a
 * document being prepared should not be refetched for that reason.
 */
export const exportKeys = {
  all: ['report-exports'] as const,
  recent: () => ['report-exports', 'recent'] as const,
  one: (id: string) => ['report-exports', id] as const,
}

/** Still being prepared, or an emailed copy still waiting for the mail queue. */
const isActive = (item: ReportExport | undefined) =>
  item?.status === 'pending' ||
  item?.status === 'processing' ||
  (item?.status === 'completed' && (item.deliveries ?? []).some((d) => d.status === 'queued'))

/**
 * One document, polled every 1.5 s while the server prepares it (or mails it)
 * and not at all once it is ready, failed or expired.
 */
export function useExportStatus(id: string | null) {
  return useQuery({
    queryKey: exportKeys.one(id ?? ''),
    queryFn: () => fetchExport(id as string),
    enabled: id !== null,
    refetchInterval: (query) => (isActive(query.state.data) ? 1500 : false),
  })
}

export function useRecentExports(enabled: boolean) {
  return useQuery({
    queryKey: exportKeys.recent(),
    queryFn: fetchRecentExports,
    enabled,
    staleTime: 10_000,
  })
}

export function useCreateExport() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (request: Parameters<typeof createExport>[0]) => createExport(request),
    onSuccess: (item) => {
      client.setQueryData(exportKeys.one(item.id), item)
      void client.invalidateQueries({ queryKey: exportKeys.recent() })
    },
  })
}

export function useCheckRecipients() {
  return useMutation({
    mutationFn: (request: Parameters<typeof checkRecipients>[0]) => checkRecipients(request),
  })
}

export function useSendReportEmail() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (request: Parameters<typeof sendReportEmail>[0]) => sendReportEmail(request),
    onSuccess: (item) => {
      client.setQueryData(exportKeys.one(item.id), item)
      void client.invalidateQueries({ queryKey: exportKeys.recent() })
    },
  })
}
