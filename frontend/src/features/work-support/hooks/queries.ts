import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { fetchSupportRequestInbox, fetchTechnicianSubmissions } from '../api/workSupportApi'
import type { SubmissionKind, WorkSupportStatus } from '../types'

export const workSupportKeys = {
  all: ['work-support'] as const,
  mine: (status?: string) => ['work-support', 'mine', status ?? 'all'] as const,
  inbox: (status?: string) => ['work-support', 'inbox', status ?? 'all'] as const,
  submissions: (kind?: string) => ['work-support', 'submissions', kind ?? 'all'] as const,
}

/** The administrator inbox (FR-WSR-010). */
export function useSupportRequestInbox(status?: WorkSupportStatus | 'pending') {
  return useQuery({
    queryKey: workSupportKeys.inbox(status),
    queryFn: () => fetchSupportRequestInbox(status),
    placeholderData: keepPreviousData,
  })
}

/**
 * The technician's combined submission history (FR-WSR-009).
 *
 * Its own key rather than a variant of `mine`, so a support-request mutation
 * invalidating the tree refreshes both surfaces without either needing to know
 * about the other.
 */
export function useTechnicianSubmissions(kind?: SubmissionKind) {
  return useQuery({
    queryKey: workSupportKeys.submissions(kind),
    queryFn: () => fetchTechnicianSubmissions(kind),
    placeholderData: keepPreviousData,
  })
}
