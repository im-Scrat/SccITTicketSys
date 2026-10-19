import { useMutation, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
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
import type { ReadyPhoto } from '@/hooks/usePreparedPhotos'
import type { ReportOutcome, ReportPhotoFailure } from '../reportOutcome'
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
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ticketsKeys.all }),
      // Phase 2.11 (DD-100): reporting, resolving or reopening a ticket can move
      // its PC's repair status, which the asset pages and the Floorplan show.
      queryClient.invalidateQueries({ queryKey: ['assets'] }),
      queryClient.invalidateQueries({ queryKey: ['floor-plan'] }),
    ])
}

/** Where a report stands while it is being sent, for the form's status line. */
export type ReportProgress =
  { phase: 'creating' } | { phase: 'uploading'; current: number; total: number }

export interface ReportTicketInput {
  payload: TicketPayload
  /**
   * Prepared photos, in the order the reporter added them, each with the
   * number and name the form showed — so a failure is named as the reporter
   * saw it, not by its place among the photos actually sent.
   */
  photos: ReadyPhoto[]
  /**
   * Photos refused while the form waited for them to be prepared. The report
   * is still sent; these are reported with the failed uploads.
   */
  refused?: ReportPhotoFailure[]
  onProgress?: (progress: ReportProgress) => void
}

/**
 * Why one photo upload failed, in words a reporter can act on. Shared by the
 * report form and the ticket's "Photos & files" tab, so a refusal reads the
 * same on both — and never as the throttle's bare "Too Many Attempts.".
 */
export function uploadFailureReason(caught: unknown): string {
  if (!isAxiosError(caught)) return 'It could not be attached.'
  const status = caught.response?.status

  if (status === undefined) return 'The connection dropped before it was sent.'
  if (status === 429) return 'Too many photos were sent in the last hour — try again later.'
  if (status === 401) return 'Your session ended before it was sent.'
  return getErrorMessage(caught, 'It could not be attached.')
}

/**
 * The reason the photos after this failure are not even tried, or `null` to
 * carry on. Each would only collect the same refusal — and a refused upload
 * still travels in full before the server can refuse it.
 */
function stopReason(caught: unknown): string | null {
  const status = isAxiosError(caught) ? caught.response?.status : undefined
  if (status === 401 || status === 403) {
    return 'Not sent — you need to sign in again before photos can be added.'
  }
  if (status === 429) {
    return 'Not sent — too many photos were sent in the last hour; add it again later under Photos & files.'
  }
  return null
}

/**
 * Report a problem, then attach its photos (SRS FR-TKT-001/008; SDD DD-80).
 *
 * **Create first, then upload each photo through the one attachment endpoint**
 * (DD-45) — the ticket is the thing that matters, and a photo that fails must
 * never cost the reporter their report. So:
 *
 *  - If creating fails, the mutation rejects and nothing was filed: the form
 *    shows why and keeps everything typed.
 *  - Once the server has answered 201, **nothing rejects**. Each photo goes up
 *    in turn; a failure is recorded with its position and reason and the next
 *    one is tried. The result says what was attached and what was not, and the
 *    ticket page tells the reporter (`reportOutcome`).
 *  - A 401 or 403 means the session can no longer attach anything, and a 429
 *    means the hourly upload limit is spent, so the remaining photos are
 *    marked failed without being sent rather than each travelling in full to
 *    collect the same refusal.
 *  - Failures are named **as the form showed them** — its number and the
 *    name as picked — and photos the form refused while it waited to send
 *    are listed with them, so nothing the reporter added goes unmentioned.
 *  - Uploads are **sequential**: one 3 MB photo at a time keeps school Wi-Fi
 *    usable and makes "Attaching photo 2 of 3" true. That count is of the
 *    uploads only.
 *
 * The tickets namespace is invalidated **once**, after everything — not per
 * photo, as `useUploadAttachment` does, which would refetch the feed, the list
 * and the dashboard five times for one report.
 */
export function useReportTicket() {
  const invalidate = useInvalidateTickets()

  return useMutation({
    mutationFn: async ({ payload, photos, refused = [], onProgress }: ReportTicketInput) => {
      const tell = (progress: ReportProgress) => {
        try {
          onProgress?.(progress)
        } catch {
          // A progress line is never worth failing a report over.
        }
      }

      tell({ phase: 'creating' })
      const created = await createTicket(payload)
      const ticketId = created.data.id

      const outcome: ReportOutcome = { ticketId, attached: 0, failed: [...refused] }
      let stopped: string | null = null

      for (const [position, photo] of photos.entries()) {
        const failure = { index: photo.index, name: photo.name }
        if (stopped) {
          outcome.failed.push({ ...failure, message: stopped })
          continue
        }

        tell({ phase: 'uploading', current: position + 1, total: photos.length })
        try {
          await uploadAttachment(ticketId, photo.file)
          outcome.attached += 1
        } catch (caught) {
          outcome.failed.push({ ...failure, message: uploadFailureReason(caught) })
          stopped = stopReason(caught)
        }
      }

      // In the form's order, wherever each failure came from.
      outcome.failed.sort((a, b) => a.index - b.index)
      return outcome
    },
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
