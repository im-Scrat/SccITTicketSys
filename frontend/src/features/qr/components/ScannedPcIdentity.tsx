import { MapPin, QrCode } from 'lucide-react'
import { Surface } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { ScannedPcUnit } from '../types'

/**
 * "Am I standing at the right machine?" — answered first, and from arm's length.
 *
 * This is the one place in the product where the reader is holding a phone at
 * waist height under a desk, so the unit code is set larger than any heading
 * elsewhere in the app and the location sits directly under it. Everything a
 * technician uses to confirm identity is above the fold; everything they only
 * consult occasionally is further down the page.
 *
 * `condition` and `status` are rendered as text-bearing pills, never colour
 * alone — the shared status vocabulary's rule, and it matters more here than
 * anywhere: this screen gets read in a badly lit server cupboard.
 */
export function ScannedPcIdentity({ unit }: { unit: ScannedPcUnit }) {
  const place = [unit.location?.room, unit.location?.floor, unit.location?.building]
    .filter(Boolean)
    .join(' · ')

  const hardware = [unit.brand, unit.model].filter(Boolean).join(' ')

  return (
    <Surface className="p-6 sm:p-8">
      <div className="flex flex-col gap-5">
        <div className="flex flex-col gap-1.5">
          <p className="font-mono text-3xl leading-none font-semibold tracking-tight text-ink sm:text-4xl">
            {unit.unit_code}
          </p>
          {unit.pc_name && <p className="text-base font-medium text-ink">{unit.pc_name}</p>}
          {hardware && <p className="text-sm text-muted">{hardware}</p>}
        </div>

        {place && (
          <p className="flex items-start gap-2 text-sm text-muted">
            <MapPin className="mt-px size-4 shrink-0" aria-hidden="true" />
            <span>{place}</span>
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          <StatePill label={unit.status_label} tone={toneForStatus(unit.status)} />
          <StatePill label={unit.condition_label} tone={toneForCondition(unit.condition)} />
        </div>

        {/*
          The label's own state. Shown only when it is *not* the live one, so a
          technician holding a sticker the system has since replaced finds out
          here rather than after filing work against it.
        */}
        {unit.qr !== null && unit.qr.status !== 'active' && (
          <p className="flex items-start gap-2 text-sm text-warning-strong">
            <QrCode className="mt-px size-4 shrink-0" aria-hidden="true" />
            <span>This label is {unit.qr.status_label.toLowerCase()}. Ask for a replacement.</span>
          </p>
        )}
      </div>
    </Surface>
  )
}

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

const toneStyles: Record<Tone, string> = {
  neutral: 'bg-surface-sunken text-ink border-control-border',
  info: 'bg-info-subtle text-info border-info',
  success: 'bg-success-subtle text-success-strong border-success-strong',
  warning: 'bg-warning-subtle text-warning-strong border-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong border-danger-strong',
}

function StatePill({ label, tone }: { label: string; tone: Tone }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full border-2 px-3 py-1 text-xs font-semibold',
        toneStyles[tone],
      )}
    >
      {label}
    </span>
  )
}

/*
 * Mapped here rather than sent by the server because the scan panel is a
 * narrow projection by design (DD-49) — adding a `tone` field to it to save
 * this function would widen the payload for a presentation detail. An
 * unrecognised value renders neutral: a state nobody chose a colour for should
 * look plain rather than have one invented.
 */
function toneForStatus(status: string): Tone {
  if (status === 'under_maintenance' || status === 'offline') return 'warning'
  if (status === 'retired') return 'danger'
  if (status === 'online') return 'success'
  if (status === 'assigned') return 'info'

  return 'neutral'
}

function toneForCondition(condition: string): Tone {
  if (condition === 'working') return 'success'
  if (condition === 'faulty') return 'danger'
  if (condition === 'for_repair') return 'warning'

  return 'neutral'
}
