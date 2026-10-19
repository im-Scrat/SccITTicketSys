import { cn } from '@/lib/cn'
import type { WorkSupportStatus } from '../types'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

/**
 * The six states, and only the six (SRS FR-WSR-004).
 *
 * Colour is never the only signal: every pill carries its label, so the meaning
 * survives grayscale and colour-blindness — the shared status vocabulary's rule,
 * followed here rather than restated.
 *
 * `clarification_requested` is warning rather than info on purpose: it is the
 * one state that needs the *technician* to do something, and a technician
 * scanning their tracking page should be able to find it without reading.
 */
const TONES: Record<WorkSupportStatus, Tone> = {
  submitted: 'info',
  clarification_requested: 'warning',
  approved: 'success',
  declined: 'danger',
  cancelled: 'neutral',
  closed: 'neutral',
}

const styles: Record<Tone, string> = {
  neutral: 'bg-surface-sunken text-ink border-control-border',
  info: 'bg-info-subtle text-info border-info',
  success: 'bg-success-subtle text-success-strong border-success-strong',
  warning: 'bg-warning-subtle text-warning-strong border-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong border-danger-strong',
}

const dots: Record<Tone, string> = {
  neutral: 'bg-faint',
  info: 'bg-info',
  success: 'bg-success-strong',
  warning: 'bg-warning-strong',
  danger: 'bg-danger-strong',
}

export function SupportRequestStatusPill({
  status,
  label,
  className,
}: {
  status: WorkSupportStatus
  /** The server's own label — never re-derived from the value here. */
  label: string
  className?: string
}) {
  const tone = TONES[status] ?? 'neutral'

  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-full border-2 px-3 py-1 text-xs font-semibold',
        styles[tone],
        className,
      )}
    >
      <span className={cn('size-2.5 rounded-full', dots[tone])} aria-hidden="true" />
      {label}
    </span>
  )
}
