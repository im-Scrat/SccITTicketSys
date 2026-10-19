/**
 * Shared date/label formatting for every operational surface.
 *
 * Promoted out of the User Management slice in Phase 2.4 so Locations (and the
 * modules after it) format timestamps identically — one reading of a date across
 * the console. `features/users/lib/format.ts` re-exports these for its existing
 * call sites; behaviour is unchanged.
 */

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * Compact relative time: "just now", "3d ago" or "in 3d", or a date past a week.
 *
 * Both directions, because dashboards show deadlines and scheduled visits as well
 * as things that happened. A time ahead of now used to fall into the "just now"
 * branch, so a ticket due tomorrow read as if it had just been touched.
 */
export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return '—'
  const then = new Date(iso).getTime()
  const diff = Date.now() - then
  const future = diff < 0
  const mins = Math.round(Math.abs(diff) / 60_000)
  if (mins < 1) return 'just now'

  const phrase = (amount: number, unit: string) =>
    future ? `in ${amount}${unit}` : `${amount}${unit} ago`

  if (mins < 60) return phrase(mins, 'm')
  const hours = Math.round(mins / 60)
  if (hours < 24) return phrase(hours, 'h')
  const days = Math.round(hours / 24)
  if (days < 7) return phrase(days, 'd')
  return formatDate(iso)
}

export function titleCase(value: string | null | undefined): string {
  if (!value) return '—'
  return value.charAt(0).toUpperCase() + value.slice(1)
}

/**
 * A duration in minutes as a reader would say it: "45 min", "2 h 10 min",
 * "3 d 4 h". Null (no data) renders as an em dash, never as "0 min" — no
 * measurement is not an instant one.
 */
export function formatMinutes(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined || Number.isNaN(minutes)) return '—'
  const total = Math.max(0, Math.round(minutes))
  if (total < 60) return `${total} min`
  const hours = Math.floor(total / 60)
  if (hours < 24) {
    const rest = total % 60
    return rest > 0 ? `${hours} h ${rest} min` : `${hours} h`
  }
  const days = Math.floor(hours / 24)
  const restHours = hours % 24
  return restHours > 0 ? `${days} d ${restHours} h` : `${days} d`
}
