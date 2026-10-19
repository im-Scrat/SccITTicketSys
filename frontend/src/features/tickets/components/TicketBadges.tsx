import { UserRound } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { PriorityRef, SlaPosture, StatusRef } from '../types'

type Tone = 'neutral' | 'info' | 'primary' | 'success' | 'warning' | 'danger'

const toneStyles: Record<Tone, string> = {
  neutral: 'bg-surface-sunken text-ink border-control-border',
  info: 'bg-info-subtle text-info border-info',
  primary: 'bg-primary-subtle text-primary-strong border-primary-strong',
  success: 'bg-success-subtle text-success-strong border-success-strong',
  warning: 'bg-warning-subtle text-warning-strong border-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong border-danger-strong',
}

const toneDots: Record<Tone, string> = {
  neutral: 'bg-faint',
  info: 'bg-info',
  primary: 'bg-primary',
  success: 'bg-success-strong',
  warning: 'bg-warning-strong',
  danger: 'bg-danger-strong',
}

/*
 * `ticket_statuses.color` and `ticket_priorities.color` hold arbitrary hexes so
 * an administrator can add a status later without a deployment. They are
 * deliberately **not** rendered: an arbitrary hex has no contrast guarantee, and
 * the design system's status hues are universal and never re-skinned. The seeded
 * vocabulary is mapped to the system's tones here, and anything unrecognised
 * falls back to neutral — a status nobody chose a tone for should look plain,
 * not invent a colour.
 */
const statusTones: Record<string, Tone> = {
  open: 'info',
  assigned: 'info',
  'in-progress': 'primary',
  'on-hold': 'warning',
  resolved: 'success',
  closed: 'neutral',
  cancelled: 'neutral',
}

const priorityTones: Record<string, Tone> = {
  low: 'neutral',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
}

function Pill({ tone, label, className }: { tone: Tone; label: string; className?: string }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-full border-2 px-3 py-1 text-xs font-semibold',
        toneStyles[tone],
        className,
      )}
    >
      <span className={cn('size-2.5 rounded-full', toneDots[tone])} aria-hidden="true" />
      {label}
    </span>
  )
}

/**
 * Where a ticket stands in its lifecycle.
 *
 * Never colour-only: the written label carries the meaning, the dot is
 * decorative, and the 2px border gives the wash a defined edge for a reader who
 * perceives little of the fill.
 */
export function TicketStatusBadge({
  status,
  className,
}: {
  status: StatusRef
  className?: string
}) {
  return (
    <Pill
      tone={statusTones[status.slug ?? ''] ?? 'neutral'}
      label={status.label ?? 'Unknown status'}
      className={className}
    />
  )
}

export function TicketPriorityBadge({
  priority,
  className,
}: {
  priority: PriorityRef
  className?: string
}) {
  if (!priority.label) return null

  return (
    <Pill
      tone={priorityTones[priority.slug ?? ''] ?? 'neutral'}
      label={`${priority.label} priority`}
      className={className}
    />
  )
}

/**
 * The priority's dot on its own, for the report form's choices. Decorative —
 * the choice's written name carries the meaning. Same tone map as the badge, so
 * Urgent (slug `critical`) is the one red dot, as the Reserved-Red rule allows
 * for a critical level, and a priority nobody mapped is plain.
 */
export function PriorityDot({ slug, className }: { slug: string; className?: string }) {
  return (
    <span
      className={cn(
        'inline-block size-3 shrink-0 rounded-full',
        toneDots[priorityTones[slug] ?? 'neutral'],
        className,
      )}
      aria-hidden="true"
    />
  )
}

/**
 * "Reporter's choice" — the person who reported the fault picked this priority
 * themselves, and nothing has overridden it since (SDD DD-75).
 *
 * It exists so an administrator can *find* self-declared priorities: teachers
 * may choose Urgent, and the control on that is a person lowering it, which is
 * only possible if the ticket says where its priority came from. The source
 * reaches staff projections only — a reporter's own record never carries it — so
 * this renders nothing for anyone else without having to ask who is looking.
 * Neutral on purpose: a reporter's choice is information, not a warning.
 */
export function ReporterChoiceMarker({
  priority,
  className,
}: {
  priority: PriorityRef
  className?: string
}) {
  if (priority.source !== 'reporter') return null

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border-2 border-dashed border-control-border px-3 py-1 text-xs font-semibold text-ink',
        className,
      )}
    >
      <UserRound size={14} aria-hidden="true" />
      Reporter’s choice
    </span>
  )
}

/**
 * How a ticket stands against its clock (SRS FR-TKT-017).
 *
 * Staff-only by construction — the posture only ever arrives on the resources a
 * requester never receives. Renders nothing when the ticket is comfortably
 * inside its budget: a badge that says "fine" on every row is noise that trains
 * people to stop reading the column.
 */
export function SlaBadge({ sla, className }: { sla: SlaPosture | null; className?: string }) {
  if (sla === null) return null

  if (sla.breached) {
    return <Pill tone="danger" label="Overdue" className={className} />
  }

  if (sla.at_risk) {
    const hours =
      sla.minutes_to_resolution !== null
        ? Math.max(0, Math.round(sla.minutes_to_resolution / 60))
        : null

    return (
      <Pill
        tone="warning"
        label={hours !== null ? `Due in ${hours}h` : 'Due soon'}
        className={className}
      />
    )
  }

  return null
}
