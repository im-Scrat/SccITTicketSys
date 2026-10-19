import { api } from '@/services/api'
import type {
  ProofResult,
  ProofSubmission,
  ScannedPcUnit,
  ScanOutcome,
  WorkTargets,
} from '../types'

/**
 * The scanned technician workflow (SRS FR-QR-005/012, FR-MNT-009/010/012).
 *
 * Every call is keyed by the **printed code**, never by a PC identifier. The
 * client has no way to name a machine directly and is not given one: the server
 * resolves the code to a unit on every request, which is what stops a
 * manipulated identifier from reaching another machine's workflow.
 */

/**
 * Record the scan and ask what happens next.
 *
 * Deliberately tolerant of 403: a refusal is a *result* of scanning, not a
 * transport failure, and the page renders it as an explanation rather than an
 * error boundary. Every other status still rejects.
 */
export async function scanCode(code: string): Promise<ScanOutcome> {
  const { data } = await api.post<ScanOutcome>(
    `/qr/${encodeURIComponent(code)}/scan`,
    {},
    { validateStatus: (status) => status === 200 || status === 403 },
  )

  return data
}

/** The scan-scoped panel. A 403 here is a genuine refusal and propagates. */
export async function fetchScannedPanel(code: string): Promise<ScannedPcUnit> {
  const { data } = await api.get<{ data: ScannedPcUnit }>(`/qr/${encodeURIComponent(code)}/panel`)

  return data.data
}

/** The jobs on this machine the caller may submit proof against. */
export async function fetchWorkTargets(code: string): Promise<WorkTargets> {
  const { data } = await api.get<{ data: WorkTargets }>(`/qr/${encodeURIComponent(code)}/work`)

  return data.data
}

/**
 * Submit proof of work.
 *
 * Multipart because it carries photographs. `scan_id` is what makes the write
 * idempotent on the physical scan rather than on this request (FR-MNT-012), so
 * a retry after a dropped response updates the same record instead of opening a
 * second one — the client does not have to be clever about retries.
 */
export async function submitProofOfWork(
  code: string,
  submission: ProofSubmission,
): Promise<ProofResult> {
  const form = new FormData()

  form.append('scan_id', submission.scanId)
  form.append('resolution', submission.resolution)
  form.append('outcome', submission.outcome)

  if (submission.maintenanceId) form.append('maintenance_id', submission.maintenanceId)
  if (submission.diagnosis) form.append('diagnosis', submission.diagnosis)
  if (submission.caption) form.append('caption', submission.caption)

  if (submission.evidence.length > 0) {
    form.append('evidence_type', submission.evidenceStage)
    submission.evidence.forEach((file) => form.append('evidence[]', file))
  }

  const { data } = await api.post<{ data: ProofResult }>(
    `/qr/${encodeURIComponent(code)}/proof`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )

  return data.data
}
