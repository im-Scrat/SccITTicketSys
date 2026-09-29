import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  type AssignmentAction,
  assignTicket,
  changePriority,
  changeTicketStatus,
  createTicket,
  deleteAttachment,
  deleteComment,
  markDuplicate,
  postComment,
  reportTicketNotFixed,
  respondToAssignment,
  type TicketPayload,
  toggleVote,
  updateComment,
  updateTicket,
  uploadAttachment,
} from '../api/ticketsApi'
import { ticketsKeys } from './queries'

/**
 * Invalidate the whole tickets namespace.
 *
 * Any write moves several views at once — a status change alters the feed card,
 * the directory row, the detail payload, the technician queue and the dashboard
 * counts together — so a namespace-wide invalidation is both simplest and
 * correct. Same reasoning as the Locations and Assets slices.
 */
function useInvalidateTickets() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ticketsKeys.all })
}

export function useCreateTicket() {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: (payload: TicketPayload) => createTicket(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateTicket(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: (payload: Partial<TicketPayload>) => updateTicket(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useChangeTicketStatus(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({ status, remarks }: { status: string; remarks?: string }) =>
      changeTicketStatus(id, status, remarks),
    onSuccess: () => void invalidate(),
  })
}

/** WP-J NOT FIXED — see `reportTicketNotFixed`. */
export function useReportNotFixed(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({ remarks }: { remarks?: string }) => reportTicketNotFixed(id, remarks),
    onSuccess: () => void invalidate(),
  })
}

export function useAssignTicket(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({ technician, remarks }: { technician: string; remarks?: string }) =>
      assignTicket(id, technician, remarks),
    onSuccess: () => void invalidate(),
  })
}

export function useChangePriority(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({ priority, reason }: { priority: string; reason?: string }) =>
      changePriority(id, priority, reason),
    onSuccess: () => void invalidate(),
  })
}

export function useMarkDuplicate(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: (duplicateOf: string | null) => markDuplicate(id, duplicateOf),
    onSuccess: () => void invalidate(),
  })
}

export function useRespondToAssignment(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({
      action,
      reason,
      remarks,
    }: {
      action: AssignmentAction
      reason?: string
      remarks?: string
    }) => respondToAssignment(id, action, { reason, remarks }),
    onSuccess: () => void invalidate(),
  })
}

export function usePostComment(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({
      body,
      isInternal,
      parent,
    }: {
      body: string
      isInternal?: boolean
      parent?: string
    }) => postComment(id, body, isInternal ?? false, parent),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateComment() {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: ({ commentId, body }: { commentId: string; body: string }) =>
      updateComment(commentId, body),
    onSuccess: () => void invalidate(),
  })
}

export function useDeleteComment() {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: (commentId: string) => deleteComment(commentId),
    onSuccess: () => void invalidate(),
  })
}

/**
 * Toggle an upvote.
 *
 * Returns the authoritative `{ voted, upvote_count }` — the count comes from the
 * database counter-cache trigger, which is the only thing that knows the value
 * after concurrent votes. `VoteButton` moves its own number immediately and
 * reconciles with this, so the interaction feels instant without the client ever
 * inventing a count.
 */
export function useToggleVote(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: () => toggleVote(id),
    onSuccess: () => void invalidate(),
  })
}

export function useUploadAttachment(id: string) {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: (file: File) => uploadAttachment(id, file),
    onSuccess: () => void invalidate(),
  })
}

export function useDeleteAttachment() {
  const invalidate = useInvalidateTickets()
  return useMutation({
    mutationFn: (attachmentId: string) => deleteAttachment(attachmentId),
    onSuccess: () => void invalidate(),
  })
}
