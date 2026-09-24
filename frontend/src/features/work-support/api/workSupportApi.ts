import { api } from '@/services/api'
import type {
  Paginated,
  RaiseSupportRequest,
  SubmissionKind,
  SupportRequest,
  TechnicianSubmission,
  WorkSupportStatus,
} from '../types'

/**
 * Work support requests (SRS FR-WSR-001..014).
 *
 * Two surfaces on one entity, and the split is the authorization boundary:
 * `/work-support-requests` is scoped to the caller's own submissions, while
 * `/admin/work-support-requests` is the estate-wide inbox. The client cannot
 * cross between them by changing a parameter, because there is no parameter —
 * they are different routes with different server-side scopes.
 *
 * **Every state change is a named POST.** There is deliberately no `update()`
 * here that takes a status: FR-WSR-004 requires transitions to come from the
 * server's map rather than from the client, so the decision *is* the endpoint.
 */

/* --------------------------------------------------------- the technician */

/** Raise a request from the scanned workflow. Addressed by the printed code. */
export async function raiseSupportRequest(
  code: string,
  payload: RaiseSupportRequest,
): Promise<SupportRequest> {
  const form = new FormData()

  form.append('explanation', payload.explanation)
  if (payload.maintenanceId) form.append('maintenance_id', payload.maintenanceId)
  if (payload.caption) form.append('caption', payload.caption)

  payload.items.forEach((item, index) => {
    if (item.hardware_model !== undefined) {
      form.append(`items[${index}][hardware_model]`, String(item.hardware_model))
    }
    if (item.description) form.append(`items[${index}][description]`, item.description)
    form.append(`items[${index}][quantity]`, String(item.quantity))
    if (item.remarks) form.append(`items[${index}][remarks]`, item.remarks)
  })

  payload.evidence.forEach((file) => form.append('evidence[]', file))

  const { data } = await api.post<{ data: SupportRequest }>(
    `/qr/${encodeURIComponent(code)}/support-requests`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )

  return data.data
}

/** Everything this technician personally submitted (FR-WSR-009). */
export async function fetchMySupportRequests(
  status?: WorkSupportStatus,
): Promise<Paginated<SupportRequest>> {
  const { data } = await api.get<Paginated<SupportRequest>>('/work-support-requests', {
    params: status ? { status } : undefined,
  })

  return data
}

export async function cancelSupportRequest(id: string, note?: string): Promise<SupportRequest> {
  const { data } = await api.post<{ data: SupportRequest }>(
    `/work-support-requests/${id}/cancel`,
    note ? { cancellation_note: note } : {},
  )

  return data.data
}

export async function acknowledgeSchedule(id: string): Promise<SupportRequest> {
  const { data } = await api.post<{ data: SupportRequest }>(
    `/work-support-requests/${id}/acknowledge`,
  )

  return data.data
}

/* ------------------------------------------------------ the administrator */

/** The inbox (FR-WSR-010). `pending` is the server's own undecided set. */
export async function fetchSupportRequestInbox(
  status?: WorkSupportStatus | 'pending',
): Promise<Paginated<SupportRequest>> {
  const { data } = await api.get<Paginated<SupportRequest>>('/admin/work-support-requests', {
    params: status ? { status } : undefined,
  })

  return data
}

/** **Decision A** — approve and reschedule (FR-WSR-006). */
export async function approveSupportRequest(
  id: string,
  payload: { rescheduled_to: string; reschedule_reason?: string },
): Promise<SupportRequest> {
  const { data } = await api.post<{ data: SupportRequest }>(
    `/admin/work-support-requests/${id}/approve`,
    payload,
  )

  return data.data
}

/** **Decision B** — ask for a face-to-face discussion (FR-WSR-007). */
export async function requestClarification(
  id: string,
  payload: { clarification_reason: string; proposed_meeting_at?: string },
): Promise<SupportRequest> {
  const { data } = await api.post<{ data: SupportRequest }>(
    `/admin/work-support-requests/${id}/request-clarification`,
    payload,
  )

  return data.data
}

/** **Decision C** — decline, with a reason the server insists on (FR-WSR-008). */
export async function declineSupportRequest(
  id: string,
  declineReason: string,
): Promise<SupportRequest> {
  const { data } = await api.post<{ data: SupportRequest }>(
    `/admin/work-support-requests/${id}/decline`,
    { decline_reason: declineReason },
  )

  return data.data
}

export async function closeSupportRequest(id: string): Promise<SupportRequest> {
  const { data } = await api.post<{ data: SupportRequest }>(
    `/admin/work-support-requests/${id}/close`,
  )

  return data.data
}

/* ---------------------------------------------------------- Stage F reads */

/**
 * Fetch one attachment as a blob (SRS FR-WSR-003).
 *
 * Addressed **through its request**, matching the API: there is no flat
 * attachment endpoint, and the client must not invent the shape of one. The
 * bytes come through the authenticated client and become an object URL, which
 * is what lets the server force `Content-Disposition: attachment` on documents
 * without breaking image previews (DD-45) — and why no storage path is ever
 * exposed.
 */
export async function fetchAttachmentBlob(requestId: string, attachmentId: string): Promise<Blob> {
  const { data } = await api.get<Blob>(
    `/work-support-requests/${requestId}/attachments/${attachmentId}`,
    { responseType: 'blob' },
  )

  return data
}

/** The technician's combined submission history (FR-WSR-009). */
export async function fetchTechnicianSubmissions(
  kind?: SubmissionKind,
): Promise<Paginated<TechnicianSubmission>> {
  const { data } = await api.get<Paginated<TechnicianSubmission>>('/technician/submissions', {
    params: kind ? { kind } : undefined,
  })

  return data
}
