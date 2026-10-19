import { AlertTriangle, ShieldCheck, Wrench } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { MaintenanceStatusRef, MaintenanceTone, MaintenanceTypeRef } from '../types'

const toneStyles: Record<MaintenanceTone, string> = {
  neutral: 'bg-surface-sunken text-ink border-control-border',
  info: 'bg-info-subtle text-info border-info',
  success: 'bg-success-subtle text-success-strong border-success-strong',
  warning: 'bg-warning-subtle text-warning-strong border-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong border-danger-strong',
}

const toneDots: Record<MaintenanceTone, string> = {
  neutral: 'bg-faint',
  info: 'bg-info',
  success: 'bg-success-strong',
  warning: 'bg-warning-strong',
  danger: 'bg-danger-strong',
}

/*
 * The tone arrives from the API (`MaintenanceListResource::statusTone()`) rather
 * than being decided here — the same stance `AssetStatus::tone()` established,
 * so a status added later gets its colour from one place. An unrecognised tone
 * falls back to neutral: a state nobody chose a colour for should look plain
 * rather than invent one.
 *
 * Colour is never the only signal. Every pill carries its text label, and the
 * overdue and preventive markers carry an icon as well, so the meaning survives
 * grayscale and colour-blindness (NFR-ACC-004).
 */
function Pill({
  tone,
  label,
  icon,
  className,
}: {
  tone: MaintenanceTone
  label: string
  icon?: React.ReactNode
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
      {icon ?? (
        <span
          className={cn('size-2.5 rounded-full', toneDots[tone] ?? toneDots.neutral)}
          aria-hidden="true"
        />
      )}
      {label}
    </span>
  )
}

export function MaintenanceStatusBadge({
  status,
  className,
}: {
  status: MaintenanceStatusRef
  className?: string
}) {
  return <Pill tone={status.tone} label={status.label} className={className} />
}

/**
 * Preventive versus corrective.
 *
 * Worth a badge of its own because it changes what completing the visit
 * requires: corrective work must carry evidence, preventive need not
 * (FR-MNT-010). A technician seeing "Corrective" on the row is seeing the rule
 * that will apply to them at the end of the job.
 */
export function MaintenanceTypeBadge({
  type,
  className,
}: {
  type: MaintenanceTypeRef
  className?: string
}) {
  if (!type.label) return null

  return (
    <Pill
      tone={type.is_preventive ? 'info' : 'neutral'}
      label={type.label}
      icon={
        type.is_preventive ? (
          <ShieldCheck className="size-3.5" aria-hidden="true" />
        ) : (
          <Wrench className="size-3.5" aria-hidden="true" />
        )
      }
      className={className}
    />
  )
}

/** Shown only when a dated, still-open record has slipped past its date. */
export function OverdueBadge({ className }: { className?: string }) {
  return (
    <Pill
      tone="danger"
      label="Overdue"
      icon={<AlertTriangle className="size-3.5" aria-hidden="true" />}
      className={className}
    />
  )
}
