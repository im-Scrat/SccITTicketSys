import { useQuery } from '@tanstack/react-query'
import { fetchScannedPanel, fetchWorkTargets } from '../api/qrApi'

export const qrKeys = {
  all: ['qr'] as const,
  panel: (code: string) => ['qr', 'panel', code] as const,
  work: (code: string) => ['qr', 'work', code] as const,
}

/**
 * The scan-scoped panel for one code (SRS FR-QR-012).
 *
 * `retry: false` on purpose. The interesting failures here — 403 because the
 * technician has no work on this machine, 403 because the label was revoked —
 * are settled answers, and retrying them three times only delays the
 * explanation the person standing at the machine needs.
 */
export function useScannedPanel(code: string) {
  return useQuery({
    queryKey: qrKeys.panel(code),
    queryFn: () => fetchScannedPanel(code),
    retry: false,
    // A machine's state changes while someone is standing in front of it, so
    // this is deliberately not cached across a revisit.
    staleTime: 0,
  })
}

/** The jobs this caller may submit proof against on the scanned machine. */
export function useWorkTargets(code: string, enabled = true) {
  return useQuery({
    queryKey: qrKeys.work(code),
    queryFn: () => fetchWorkTargets(code),
    retry: false,
    enabled,
    staleTime: 0,
  })
}
