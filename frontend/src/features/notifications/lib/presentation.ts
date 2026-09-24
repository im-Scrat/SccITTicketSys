import {
  AlertTriangle,
  Bell,
  CalendarClock,
  CheckCircle2,
  CircleAlert,
  HandHelping,
  Info,
  Megaphone,
  MessageSquare,
  ShieldAlert,
  Ticket,
  UserCheck,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import type { BadgeTone } from '@/components/ui/Badge'
import type { NotificationTopicValue, NotificationTypeValue } from '../types'

/**
 * How a notification is presented: its tone, its glyph, and whether its
 * destination may be followed.
 *
 * ── Every lookup here has a default arm, deliberately ──────────────────────
 *
 * `type` and `topic` are server-side enums that will grow — D5's announcements
 * are already accepted, and WP-2.4b adds procurement topics after that. An
 * exhaustive switch would be correct on the day it was written and would throw
 * on the first notification of a kind it had never heard of, which is precisely
 * the failure a notification centre must not have: the row a user cannot see is
 * the row that mattered. So these are open maps with a fallback, and a new
 * server-side case renders as a plain notification until someone chooses a
 * glyph for it.
 *
 * ── The payload is not rendered ────────────────────────────────────────────
 *
 * `payload` is a free-form bag whose keys vary per trigger, and every value a
 * reader needs is already composed into `title` and `message` by the server.
 * There is therefore no presentation rule for it here and no component reads
 * it: the safest treatment of an open-ended structure is not to put it on the
 * screen at all. React escapes text by default, and `dangerouslySetInnerHTML`
 * appears nowhere in this slice.
 */

/**
 * Badge tone per notification type.
 *
 * `warning` and `error` are the two that carry operational weight — an SLA
 * breach and a failure — and they get the tones the rest of the console uses
 * for exactly those meanings. Everything else stays quiet: the One Voice Rule
 * gives Signal Blue to the badge on the bell, and a list where every row is
 * also blue would spend the signal that badge depends on.
 */
const TONES: Record<string, BadgeTone> = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  error: 'warning',
  ticket_update: 'neutral',
  assignment: 'primary',
  announcement: 'info',
  maintenance: 'neutral',
  system: 'outline',
}

export function notificationTone(type: NotificationTypeValue): BadgeTone {
  return TONES[type] ?? 'neutral'
}

/** Glyph per trigger — the finer identity, which `type` cannot express. */
const TOPIC_ICONS: Record<string, LucideIcon> = {
  'ticket.assigned': UserCheck,
  'ticket.reassigned': UserCheck,
  'ticket.status_changed': Ticket,
  'ticket.commented': MessageSquare,
  'ticket.sla_threshold': AlertTriangle,
  'maintenance.scheduled': CalendarClock,
  'maintenance.due': Wrench,
  'maintenance.rescheduled': CalendarClock,
  'work_support.submitted': HandHelping,
  'work_support.decided': HandHelping,
  'account.locked': ShieldAlert,
}

/** Fallback glyph per type, for a topic this build has never seen. */
const TYPE_ICONS: Record<string, LucideIcon> = {
  info: Info,
  success: CheckCircle2,
  warning: AlertTriangle,
  error: CircleAlert,
  announcement: Megaphone,
  maintenance: Wrench,
  assignment: UserCheck,
  ticket_update: Ticket,
  system: ShieldAlert,
}

export function notificationIcon(
  topic: NotificationTopicValue | null,
  type: NotificationTypeValue,
): LucideIcon {
  return (topic === null ? undefined : TOPIC_ICONS[topic]) ?? TYPE_ICONS[type] ?? Bell
}

/**
 * The destination, if it is one this application may navigate to.
 *
 * `action_url` is built by the server and is always a relative `/app/…` path
 * today — but it is stored data, and treating stored data as a trusted
 * destination is how open redirects happen. This is the FR-QR-011 discipline
 * applied to a second surface: the client asserts the shape it expects rather
 * than trusting the shape it has always received.
 *
 * Requiring the literal `/app/` prefix refuses, in one rule, every form the
 * problem takes: an absolute `https://elsewhere.example` URL, a
 * protocol-relative `//elsewhere.example`, a `javascript:` scheme, and a path
 * outside the authenticated application. Anything else renders as a row with no
 * link rather than as a link somewhere unexpected.
 */
export function safeActionPath(actionUrl: string | null | undefined): string | null {
  if (typeof actionUrl !== 'string') return null

  return actionUrl.startsWith('/app/') ? actionUrl : null
}

/**
 * Human label for a type, preferring the server's own vocabulary.
 *
 * The options come from `GET /api/notification-preferences`, so a type added
 * server-side is labelled by the server rather than by a table here that would
 * have to be kept in step with an enum it cannot see. The fallback only runs
 * before that request resolves, or for a value the metadata does not carry.
 */
export function notificationTypeLabel(
  type: NotificationTypeValue,
  options: { value: string; label: string }[] = [],
): string {
  const known = options.find((option) => option.value === type)
  if (known) return known.label

  return type
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}
