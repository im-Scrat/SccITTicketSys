import { Badge, type BadgeTone } from '@/components/ui'
import type { ImportOutcome, ImportStatus } from '../types'

const OUTCOME_TONE: Record<ImportOutcome, BadgeTone> = {
  complete: 'success',
  partial: 'warning',
  nothing_new: 'neutral',
  failed: 'warning',
  stopped: 'warning',
}

const STATUS_TONE: Partial<Record<ImportStatus, BadgeTone>> = {
  reading: 'info',
  validating: 'info',
  queued: 'info',
  importing: 'info',
  mapping: 'primary',
  review: 'primary',
  unreadable: 'warning',
  cancelled: 'outline',
  expired: 'outline',
}

/**
 * Where an import is, or — once finished — the outcome in the server's own
 * words. A failure is a warning tone, never an alarm red: nothing broke, the
 * rows are listed for a retry.
 */
export function ImportStatusBadge({
  status,
  statusLabel,
  outcome,
  outcomeLabel,
}: {
  status: ImportStatus
  statusLabel: string
  outcome: ImportOutcome | null
  outcomeLabel?: string | null
}) {
  if (status === 'finished' && outcome) {
    return <Badge tone={OUTCOME_TONE[outcome]}>{outcomeLabel ?? statusLabel}</Badge>
  }

  return <Badge tone={STATUS_TONE[status] ?? 'neutral'}>{statusLabel}</Badge>
}
