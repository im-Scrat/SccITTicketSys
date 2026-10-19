import { CheckCircle2, CircleDashed, Clock, OctagonAlert, type LucideIcon } from 'lucide-react'

/**
 * Phase 2.11 — the one PC status vocabulary (SDD DD-100).
 *
 * Three stored repair statuses, each with one fixed hue, plus a fourth display
 * category — grey **Unassigned** — derived from "this PC has no room". Every
 * screen that shows a PC's status reads this table (asset lists, the Floorplan,
 * QR, dashboards, filters), so a status can never look different on two pages.
 *
 * The server still sends `label` and `tone` with each status (`PcStatus::tone()`);
 * `pcStatus.test.ts` pins this table to those same values so the two cannot drift.
 *
 * Nothing here claims a PC is online or offline: the system has no telemetry.
 */
export const PC_STATUS_VALUES = ['available', 'pending_repair', 'needs_fix'] as const

export type PcStatusValue = (typeof PC_STATUS_VALUES)[number]

/** A status as shown: one of the three, or the derived "no room" category. */
export type PcStatusCategory = PcStatusValue | 'unassigned'

export type PcStatusTone = 'success' | 'warning' | 'danger' | 'neutral'

export interface PcStatusPresentation {
  label: string
  tone: PcStatusTone
  icon: LucideIcon
  /** One plain sentence for legends and tooltips. */
  description: string
}

export const PC_STATUS: Record<PcStatusCategory, PcStatusPresentation> = {
  available: {
    label: 'Available',
    tone: 'success',
    icon: CheckCircle2,
    description: 'Ready for use.',
  },
  pending_repair: {
    label: 'Pending Repair',
    tone: 'warning',
    icon: Clock,
    description: 'An issue was reported or a repair is under way.',
  },
  needs_fix: {
    label: 'Needs Fix',
    tone: 'danger',
    icon: OctagonAlert,
    description: 'Faulty and needs attention.',
  },
  unassigned: {
    label: 'Unassigned',
    tone: 'neutral',
    icon: CircleDashed,
    description: 'Not assigned to a room.',
  },
}

/** Tailwind classes per tone: subtle wash, same-hue border, strong text. */
export const PC_STATUS_TONE_CLASSES: Record<
  PcStatusTone,
  { wash: string; border: string; text: string; fill: string }
> = {
  success: {
    wash: 'bg-success-subtle',
    border: 'border-success',
    text: 'text-success-strong',
    fill: 'bg-success',
  },
  warning: {
    wash: 'bg-warning-subtle',
    border: 'border-warning',
    text: 'text-warning-strong',
    fill: 'bg-warning',
  },
  danger: {
    wash: 'bg-danger-subtle',
    border: 'border-danger',
    text: 'text-danger-strong',
    fill: 'bg-danger',
  },
  neutral: {
    wash: 'bg-surface-sunken',
    border: 'border-border',
    text: 'text-muted',
    fill: 'bg-faint',
  },
}

export function isPcStatus(value: unknown): value is PcStatusValue {
  return typeof value === 'string' && (PC_STATUS_VALUES as readonly string[]).includes(value)
}

/** Pre-2.11 values that can still appear in history (`activity_logs`). */
const LEGACY_LABELS: Record<string, string> = {
  assigned: 'Assigned',
  online: 'Online',
  offline: 'Offline',
  under_maintenance: 'Under Maintenance',
  retired: 'Retired',
}

/**
 * A human label for any status value found in data or history — current,
 * legacy, or unknown (returned unchanged rather than hidden).
 */
export function pcStatusLabel(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  if (isPcStatus(value)) return PC_STATUS[value].label
  return LEGACY_LABELS[value] ?? value
}

/** The category a PC is shown as: grey Unassigned wins when it has no room. */
export function pcStatusCategory(status: PcStatusValue, hasRoom: boolean): PcStatusCategory {
  return hasRoom ? status : 'unassigned'
}
