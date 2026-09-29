import { keepPreviousData, useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import {
  fetchAdminTicket,
  fetchAssignedTicket,
  fetchAssignedTickets,
  fetchAssignmentHistory,
  fetchAttachments,
  fetchComments,
  fetchDuplicates,
  fetchFeed,
  fetchMyTickets,
  fetchTicket,
  fetchTicketAiAnalysis,
  fetchTicketDashboard,
  fetchTicketDirectory,
  fetchTicketOptions,
} from '../api/ticketsApi'
import type { TicketParams } from '../types'

export const ticketsKeys = {
  all: ['tickets'] as const,
  options: () => ['tickets', 'options'] as const,
  feed: (params: TicketParams) => ['tickets', 'feed', params] as const,
  mine: (params: TicketParams) => ['tickets', 'mine', params] as const,
  duplicates: (search: string, pcUnit?: string) =>
    ['tickets', 'duplicates', search, pcUnit ?? null] as const,
  detail: (id: string) => ['tickets', 'detail', id] as const,
  adminDetail: (id: string) => ['tickets', 'admin-detail', id] as const,
  assignedDetail: (id: string) => ['tickets', 'assigned-detail', id] as const,
  directory: (params: TicketParams) => ['tickets', 'directory', params] as const,
  dashboard: () => ['tickets', 'dashboard'] as const,
  assigned: (params: TicketParams) => ['tickets', 'assigned', params] as const,
  history: (params: TicketParams) => ['tickets', 'history', params] as const,
  comments: (id: string, page: number) => ['tickets', 'comments', id, page] as const,
  attachments: (id: string) => ['tickets', 'attachments', id] as const,
  // Under the `tickets` namespace on purpose: every ticket write invalidates it,
  // so FIXED / NOT FIXED refresh the outcome shown here with no extra wiring.
  aiAnalysis: (id: string) => ['tickets', 'ai-analysis', id] as const,
}

/** Vocabularies change rarely; hold them for the session. */
export function useTicketOptions(enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.options(),
    queryFn: fetchTicketOptions,
    staleTime: 5 * 60 * 1000,
    enabled,
  })
}

/**
 * The community feed.
 *
 * Infinite rather than paged: a feed is read by scrolling, and cursor paging is
 * what keeps already-read rows from shifting when new tickets arrive.
 */
export function useTicketFeed(params: TicketParams, enabled = true) {
  return useInfiniteQuery({
    queryKey: ticketsKeys.feed(params),
    queryFn: ({ pageParam }) => fetchFeed({ ...params, cursor: pageParam as string | undefined }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    enabled,
  })
}

export function useMyTickets(params: TicketParams, enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.mine(params),
    queryFn: () => fetchMyTickets(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

/**
 * Similar open reports, checked while the reporter is still writing.
 *
 * Only runs once there is enough text to be meaningful — searching on two
 * characters would return noise and teach the reporter to ignore the panel.
 */
export function useDuplicateCheck(search: string, pcUnit?: string, enabled = true) {
  const trimmed = search.trim()

  return useQuery({
    queryKey: ticketsKeys.duplicates(trimmed, pcUnit),
    queryFn: () => fetchDuplicates({ search: trimmed, pc_unit: pcUnit }),
    enabled: enabled && (trimmed.length >= 5 || Boolean(pcUnit)),
    staleTime: 30 * 1000,
  })
}

export function useTicket(id: string | undefined) {
  return useQuery({
    queryKey: ticketsKeys.detail(id ?? ''),
    queryFn: () => fetchTicket(id as string),
    enabled: Boolean(id),
  })
}

export function useAdminTicket(id: string | undefined) {
  return useQuery({
    queryKey: ticketsKeys.adminDetail(id ?? ''),
    queryFn: () => fetchAdminTicket(id as string),
    enabled: Boolean(id),
  })
}

export function useAssignedTicket(id: string | undefined) {
  return useQuery({
    queryKey: ticketsKeys.assignedDetail(id ?? ''),
    queryFn: () => fetchAssignedTicket(id as string),
    enabled: Boolean(id),
  })
}

export function useTicketDirectory(params: TicketParams, enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.directory(params),
    queryFn: () => fetchTicketDirectory(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useTicketDashboard(enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.dashboard(),
    queryFn: fetchTicketDashboard,
    enabled,
  })
}

export function useAssignedTickets(params: TicketParams, enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.assigned(params),
    queryFn: () => fetchAssignedTickets(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useAssignmentHistory(params: TicketParams, enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.history(params),
    queryFn: () => fetchAssignmentHistory(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useTicketComments(id: string | undefined, page = 1) {
  return useQuery({
    queryKey: ticketsKeys.comments(id ?? '', page),
    queryFn: () => fetchComments(id as string, page),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  })
}

export function useTicketAttachments(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.attachments(id ?? ''),
    queryFn: () => fetchAttachments(id as string),
    enabled: Boolean(id) && enabled,
  })
}

/**
 * The AI pre-screening for one ticket (WP-I), shown on the reporter's page.
 *
 * Frugal by design: this endpoint shares the 20-per-hour `tickets` rate limiter
 * (the WP-I mandate), so it does not refetch on window focus and holds for five
 * minutes. It still refreshes immediately after FIXED / NOT FIXED, because those
 * writes invalidate the whole `tickets` namespace. A refusal (403/404) or a
 * spent limit (429) will not change on a retry, so none is attempted.
 */
export function useTicketAiAnalysis(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: ticketsKeys.aiAnalysis(id ?? ''),
    queryFn: () => fetchTicketAiAnalysis(id as string),
    enabled: Boolean(id) && enabled,
    staleTime: 5 * 60 * 1000,
    refetchOnWindowFocus: false,
    retry: (count, error) =>
      count < 1 && !(isAxiosError(error) && [403, 404, 429].includes(error.response?.status ?? 0)),
  })
}
