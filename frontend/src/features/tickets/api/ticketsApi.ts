import { api } from '@/services/api'
import type {
  CursorPaginated,
  Paginated,
  TicketAttachment,
  TicketCard,
  TicketComment,
  TicketDashboard,
  TicketDetailEnvelope,
  TicketOptions,
  TicketParams,
  TicketRow,
  TicketShowEnvelope,
} from '../types'

/** Drop empty values so they never reach the query string as `?search=`. */
function cleanParams(params: object): Record<string, string | number | boolean> {
  const out: Record<string, string | number | boolean> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') {
      out[key] = value as string | number | boolean
    }
  }
  return out
}

/* --------------------------------------------------------------- requester */

/**
 * The community feed. Cursor-paginated, so rows arriving mid-scroll cannot
 * shift what the reader has already passed.
 */
export async function fetchFeed(params: TicketParams): Promise<CursorPaginated<TicketCard>> {
  const { data } = await api.get<CursorPaginated<TicketCard>>('/tickets/feed', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchMyTickets(params: TicketParams): Promise<Paginated<TicketCard>> {
  const { data } = await api.get<Paginated<TicketCard>>('/tickets/mine', {
    params: cleanParams(params),
  })
  return data
}

/**
 * Similar open reports for a fault being described.
 *
 * Text similarity over the stored search vector, boosted for the same machine —
 * **not** AI. The UI labels it accordingly.
 */
export async function fetchDuplicates(params: {
  search?: string
  pc_unit?: string
}): Promise<TicketCard[]> {
  const { data } = await api.get<{ data: TicketCard[] }>('/tickets/duplicates', {
    params: cleanParams(params),
  })
  return data.data
}

/**
 * One ticket.
 *
 * The response is either the full record or the restricted card, decided by the
 * server from the caller's relationship to the ticket. `meta` is absent on a
 * card, which is how the client tells them apart without guessing.
 */
export async function fetchTicket(id: string): Promise<TicketShowEnvelope> {
  const { data } = await api.get<TicketShowEnvelope>(`/tickets/${id}`)
  return data
}

export interface TicketPayload {
  title: string
  description: string
  category: string
  pc_unit?: string | null
  room?: string | null
  tags?: string[]
  /** Staff only — the API prohibits it for a requester. */
  priority?: string
}

export async function createTicket(payload: TicketPayload): Promise<TicketDetailEnvelope> {
  const { data } = await api.post<TicketDetailEnvelope>('/tickets', payload)
  return data
}

export async function updateTicket(
  id: string,
  payload: Partial<Pick<TicketPayload, 'title' | 'description' | 'category' | 'tags'>>,
): Promise<TicketDetailEnvelope> {
  const { data } = await api.put<TicketDetailEnvelope>(`/tickets/${id}`, payload)
  return data
}

/**
 * Every lifecycle move goes through this one endpoint — confirm, reopen,
 * cancel, and every staff transition. The server decides which the caller may
 * make, and answers 422 naming the reachable states if not.
 */
export async function changeTicketStatus(
  id: string,
  status: string,
  remarks?: string,
): Promise<TicketDetailEnvelope> {
  const { data } = await api.put<TicketDetailEnvelope>(`/tickets/${id}/status`, { status, remarks })
  return data
}

/* ------------------------------------------------------------ participation */

export async function fetchComments(id: string, page = 1): Promise<Paginated<TicketComment>> {
  const { data } = await api.get<Paginated<TicketComment>>(`/tickets/${id}/comments`, {
    params: { page },
  })
  return data
}

export async function postComment(
  id: string,
  body: string,
  isInternal = false,
  parent?: string,
): Promise<TicketComment> {
  const { data } = await api.post<{ data: TicketComment }>(`/tickets/${id}/comments`, {
    body,
    ...(isInternal ? { is_internal: true } : {}),
    ...(parent ? { parent } : {}),
  })
  return data.data
}

export async function updateComment(commentId: string, body: string): Promise<TicketComment> {
  const { data } = await api.put<{ data: TicketComment }>(`/ticket-comments/${commentId}`, { body })
  return data.data
}

export async function deleteComment(commentId: string): Promise<void> {
  await api.delete(`/ticket-comments/${commentId}`)
}

export async function toggleVote(id: string): Promise<{ voted: boolean; upvote_count: number }> {
  const { data } = await api.post<{ data: { voted: boolean; upvote_count: number } }>(
    `/tickets/${id}/vote`,
  )
  return data.data
}

export async function fetchAttachments(id: string): Promise<TicketAttachment[]> {
  const { data } = await api.get<{ data: TicketAttachment[] }>(`/tickets/${id}/attachments`)
  return data.data
}

export async function uploadAttachment(id: string, file: File): Promise<TicketAttachment> {
  const form = new FormData()
  form.append('file', file)

  const { data } = await api.post<{ data: TicketAttachment }>(`/tickets/${id}/attachments`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function deleteAttachment(attachmentId: string): Promise<void> {
  await api.delete(`/tickets/attachments/${attachmentId}`)
}

/**
 * Attachments live on a private disk, so an `<img src>` cannot fetch one — the
 * request has to carry the session. Returns an object URL the caller must
 * revoke.
 */
export async function fetchAttachmentObjectUrl(attachmentId: string): Promise<string> {
  const { data } = await api.get<Blob>(`/tickets/attachments/${attachmentId}`, {
    responseType: 'blob',
  })
  return URL.createObjectURL(data)
}

/* ------------------------------------------------------------- technician */

export async function fetchAssignedTickets(params: TicketParams): Promise<Paginated<TicketRow>> {
  const { data } = await api.get<Paginated<TicketRow>>('/tickets/assigned', {
    params: cleanParams(params),
  })
  return data
}

/** Completed or handed-on work — readable, never writable (SDD DD-42). */
export async function fetchAssignmentHistory(params: TicketParams): Promise<Paginated<TicketRow>> {
  const { data } = await api.get<Paginated<TicketRow>>('/tickets/assigned/history', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchAssignedTicket(id: string): Promise<TicketDetailEnvelope> {
  const { data } = await api.get<TicketDetailEnvelope>(`/tickets/assigned/${id}`)
  return data
}

export type AssignmentAction = 'accept' | 'decline' | 'start' | 'hold' | 'complete'

export async function respondToAssignment(
  id: string,
  action: AssignmentAction,
  payload: { reason?: string; remarks?: string } = {},
): Promise<TicketDetailEnvelope> {
  const { data } = await api.post<TicketDetailEnvelope>(
    `/tickets/assigned/${id}/${action}`,
    payload,
  )
  return data
}

/* ---------------------------------------------------------- administration */

export async function fetchTicketDashboard(): Promise<TicketDashboard> {
  const { data } = await api.get<{ data: TicketDashboard }>('/admin/tickets/dashboard')
  return data.data
}

export async function fetchTicketDirectory(params: TicketParams): Promise<Paginated<TicketRow>> {
  const { data } = await api.get<Paginated<TicketRow>>('/admin/tickets', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchAdminTicket(id: string): Promise<TicketDetailEnvelope> {
  const { data } = await api.get<TicketDetailEnvelope>(`/admin/tickets/${id}`)
  return data
}

export async function assignTicket(
  id: string,
  technician: string,
  remarks?: string,
): Promise<TicketDetailEnvelope> {
  const { data } = await api.post<TicketDetailEnvelope>(`/admin/tickets/${id}/assign`, {
    technician,
    remarks,
  })
  return data
}

export async function changePriority(
  id: string,
  priority: string,
  reason?: string,
): Promise<TicketDetailEnvelope> {
  const { data } = await api.put<TicketDetailEnvelope>(`/admin/tickets/${id}/priority`, {
    priority,
    reason,
  })
  return data
}

export async function markDuplicate(
  id: string,
  duplicateOf: string | null,
): Promise<TicketDetailEnvelope> {
  const { data } = await api.put<TicketDetailEnvelope>(`/admin/tickets/${id}/duplicate`, {
    duplicate_of: duplicateOf,
  })
  return data
}

/* ----------------------------------------------------------------- shared */

/** Role-shaped: a requester receives empty `priorities` and `technicians`. */
export async function fetchTicketOptions(): Promise<TicketOptions> {
  const { data } = await api.get<{ data: TicketOptions }>('/tickets/options')
  return data.data
}
