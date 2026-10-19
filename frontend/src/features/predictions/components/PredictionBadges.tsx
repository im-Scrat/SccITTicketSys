import { cn } from '@/lib/cn'
import type { PredictionRiskRef, PredictionStatusRef, PredictionTone } from '../types'

const toneStyles: Record<PredictionTone, string> = {
  neutral: 'bg-surface-sunken text-ink border-control-border',
  info: 'bg-info-subtle text-info border-info',
  success: 'bg-success-subtle text-success-strong border-success-strong',
  warning: 'bg-warning-subtle text-warning-strong border-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong border-danger-strong',
}

const toneDots: Record<PredictionTone, string> = {
  neutral: 'bg-faint',
  info: 'bg-info',
  success: 'bg-success-strong',
  warning: 'bg-warning-strong',
  danger: 'bg-danger-strong',
}

/*
 * Tone arrives from the API rather than being decided here — the same stance
 * `MaintenanceStatusBadge` and `AssetStatus::tone()` take, so a status or risk
 * level added later gets its colour from one place. Colour is never the only
 * signal: every pill carries its text label (NFR-ACC-004).
 */
function Pill({
  tone,
  label,
  className,
}: {
  tone: PredictionTone
  label: string
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-full border-2 px-3 py-1 text-xs font-semibold',
        toneStyles[tone] ?? toneStyles.neutral,
        className,
      )}
    >
      <span
        className={cn('size-2.5 rounded-full', toneDots[tone] ?? toneDots.neutral)}
        aria-hidden="true"
      />
      {label}
    </span>
  )
}

export function PredictionStatusBadge({
  status,
  className,
}: {
  status: PredictionStatusRef
  className?: string
}) {
  const tone: PredictionTone =
    status.value === 'confirmed'
      ? 'warning'
      : status.value === 'dismissed'
        ? 'neutral'
        : status.value === 'expired'
          ? 'neutral'
          : 'info'

  return <Pill tone={tone} label={status.label} className={className} />
}

export function PredictionRiskBadge({
  risk,
  className,
}: {
  risk: PredictionRiskRef | null
  className?: string
}) {
  if (risk === null) return <Pill tone="neutral" label="Unrated" className={className} />

  return <Pill tone={risk.tone} label={`${risk.label} risk`} className={className} />
}
