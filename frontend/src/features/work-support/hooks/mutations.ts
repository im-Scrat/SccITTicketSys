import { useMutation, useQueryClient } from '@tanstack/react-query'
import { maintenanceKeys } from '@/features/maintenance/hooks/queries'
import { qrKeys } from '@/features/qr/hooks/queries'
import {
  acknowledgeSchedule,
  approveSupportRequest,
  cancelSupportRequest,
  closeSupportRequest,
  declineSupportRequest,
  raiseSupportRequest,
  requestClarification,
} from '../api/workSupportApi'
import type { RaiseSupportRequest } from '../types'
import { workSupportKeys } from './queries'

/**
 * Every mutation invalidates the whole `work-support` tree rather than patching
 * a cached row.
 *
 * A decision can change more than the request it was made on — approving one
 * reschedules a maintenance record and may put a ticket on hold — so a
 * hand-written cache patch would be a second, quieter model of what the server
 * did, and the two would drift on the first decision that grew a side effect.
 */
function useInvalidate() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: workSupportKeys.all })
    void queryClient.invalidateQueries({ queryKey: maintenanceKeys.all })
    void queryClient.invalidateQueries({ queryKey: qrKeys.all })
  }
}

/* --------------------------------------------------------- the technician */

export function useRaiseSupportRequest(code: string) {
  const invalidate = useInvalidate()

  return useMutation({
    mutationKey: ['work-support', 'raise', code],
    mutationFn: (payload: RaiseSupportRequest) => raiseSupportRequest(code, payload),
    onSuccess: invalidate,
  })
}

export function useCancelSupportRequest() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, note }: { id: string; note?: string }) => cancelSupportRequest(id, note),
    onSuccess: invalidate,
  })
}

export function useAcknowledgeSchedule() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: string) => acknowledgeSchedule(id),
    onSuccess: invalidate,
  })
}

/* ------------------------------------------------------ the administrator */

export function useApproveSupportRequest() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({
      id,
      ...payload
    }: {
      id: string
      rescheduled_to: string
      reschedule_reason?: string
    }) => approveSupportRequest(id, payload),
    onSuccess: invalidate,
  })
}

export function useRequestClarification() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({
      id,
      ...payload
    }: {
      id: string
      clarification_reason: string
      proposed_meeting_at?: string
    }) => requestClarification(id, payload),
    onSuccess: invalidate,
  })
}

export function useDeclineSupportRequest() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      declineSupportRequest(id, reason),
    onSuccess: invalidate,
  })
}

export function useCloseSupportRequest() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: string) => closeSupportRequest(id),
    onSuccess: invalidate,
  })
}
