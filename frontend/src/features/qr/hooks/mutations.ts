import { useMutation, useQueryClient } from '@tanstack/react-query'
import { maintenanceKeys } from '@/features/maintenance/hooks/queries'
import { scanCode, submitProofOfWork } from '../api/qrApi'
import type { ProofSubmission } from '../types'
import { qrKeys } from './queries'

/** Record a scan and resolve where it leads (SRS FR-QR-005). */
export function useScanCode() {
  return useMutation({
    mutationFn: (code: string) => scanCode(code),
  })
}

/**
 * Submit proof of work for a scanned machine (SRS FR-MNT-009/010/012).
 *
 * A fixed `mutationKey` so React Query treats a double-tap as the same mutation
 * rather than two — a UX guard, and **only** a UX guard. The write is idempotent
 * on the scan at the server, which is where it has to be: a disabled button
 * protects nobody who is not using the button.
 *
 * On success the maintenance caches are invalidated as well as the panel's,
 * because the record this just moved is the same record the technician's queue
 * and its detail page are showing.
 */
export function useSubmitProofOfWork(code: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationKey: ['qr', 'proof', code],
    mutationFn: (submission: ProofSubmission) => submitProofOfWork(code, submission),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: qrKeys.panel(code) })
      void queryClient.invalidateQueries({ queryKey: qrKeys.work(code) })
      void queryClient.invalidateQueries({ queryKey: maintenanceKeys.all })
    },
  })
}
