import { StatusPill } from '@/components/ui/StatusPill'
import { TONE_PILL } from '../lib/presentation'
import type { PcStatus } from '../types'

/**
 * A PC's status as text: the shared status pill, in the server's tone, with the
 * server's wording. The pill is what makes the meaning readable without colour;
 * the map's shapes and this label are the two non-colour channels.
 */
export function PcStatusBadge({ status }: { status: PcStatus }) {
  return <StatusPill status={TONE_PILL[status.tone]} label={status.label} />
}
