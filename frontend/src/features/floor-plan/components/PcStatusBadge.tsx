import { PcStatusBadge as SharedPcStatusBadge } from '@/components/ui/PcStatusBadge'
import type { PcStatus } from '../types'

/**
 * A PC's status as text, through the shared Phase 2.11 badge (DD-100) so the
 * map, its legend and the asset pages can never disagree about a colour.
 */
export function PcStatusBadge({ status }: { status: PcStatus }) {
  return <SharedPcStatusBadge status={status.value} />
}
