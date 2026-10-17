import { ChevronRight } from 'lucide-react'
import { useId } from 'react'
import { Link } from 'react-router-dom'
import { myTicketsViewPath, type MyTicketView } from '@/features/tickets/myTicketViews'
import { cn } from '@/lib/cn'
import type { KpiItem, KpiWidget, WidgetTone } from '../types'
import { PanelHeader } from './PanelHeader'

const MY_TICKETS = '/app/tickets/mine'

const HEADING_ID = 'my-requests-heading'

/**
 * Which list each of the server's figures opens, and the one line saying what the
 * figure counts. Keyed on the server's stable item keys — never on the label,
 * which is copy and may be reworded.
 */
const FIGURES = new Map<string, { view: MyTicketView; hint: string }>([
  ['open', { view: 'open', hint: 'Not closed yet' }],
  ['awaiting_confirmation', { view: 'awaiting', hint: 'Check the fix and confirm it' }],
  ['resolved_recently', { view: 'resolved', hint: 'Fixed in the last 30 days' }],
])

/**
 * A server `tone` is a status signal: a wash behind the row and a strong
 * same-hue figure. The label always says what the number is, so the meaning
 * survives colour-blindness and grayscale.
 */
const TONES: Record<NonNullable<WidgetTone>, { row: string; value: string }> = {
  warning: { row: 'bg-warning-subtle hover:bg-warning/20', value: 'text-warning-strong' },
  danger: { row: 'bg-danger-subtle hover:bg-danger/20', value: 'text-danger-strong' },
}

/**
 * The teacher's "My requests" panel: each figure is a link to the list of
 * exactly those tickets.
 *
 * **Built to sit level with "Reported problems"**, which is the other half of
 * the dashboard. The heading is the same {@link PanelHeader} that panel uses,
 * and the list takes the frame of a feed card (`rounded-lg border-2`) — so the
 * two columns read as one system. The header and the list are separate pieces so
 * the dashboard can put the two headings in one grid row and the two bodies in
 * the next; that, not matching numbers, is what keeps their tops level when a
 * heading wraps.
 *
 * Rows run edge to edge inside the frame, so a row's wash, the dividers between
 * rows and the frame all share one width instead of the wash sticking out past
 * the hairlines.
 *
 * The shared {@see KpiRow} could not be used here: it lays its cards out by
 * *viewport* width (`grid-cols-2 lg:grid-cols-4`), but this panel lives in a
 * 24rem column, so four ~80px cards were squeezed into 384px and their labels
 * collided. One full-width row per figure is sized by its container instead, and
 * lets a long label wrap rather than overlap the number.
 *
 * Real links, not buttons that call `navigate()`: every row goes somewhere, so
 * it should open in a new tab and announce itself as a link.
 */
export function RequestSummaryHeader({
  widget,
  className,
}: {
  widget: KpiWidget
  className?: string
}) {
  if (widget.items.length === 0) return null

  return (
    <div className={cn('min-w-0', className)}>
      <PanelHeader
        id={HEADING_ID}
        title={widget.title}
        description="Select a figure to see those tickets."
        link={{ to: widget.href ?? MY_TICKETS, label: 'View all tickets' }}
      />
    </div>
  )
}

export function RequestSummaryList({ widget }: { widget: KpiWidget }) {
  if (widget.items.length === 0) return null

  // Clipped so a row's wash follows the frame's rounded corners; the focus ring
  // is drawn inward (negative offset) so that clip never hides it.
  return (
    <ul
      aria-labelledby={HEADING_ID}
      className="flex flex-col divide-y divide-border overflow-hidden rounded-lg border-2 border-border bg-surface"
    >
      {widget.items.map((item) => (
        <SummaryRow key={item.key} item={item} />
      ))}
    </ul>
  )
}

function SummaryRow({ item }: { item: KpiItem }) {
  const hintId = useId()
  const figure = FIGURES.get(item.key)
  // A server-sent href wins. A key this client does not know stays a plain row
  // rather than a link that guesses where it should go.
  const to = item.href ?? (figure ? myTicketsViewPath(figure.view) : null)
  const tone = item.tone ? TONES[item.tone] : null
  const value = item.value.toLocaleString()

  const content = (
    <>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-strong">{item.label}</span>
        {figure && (
          <span id={hintId} className="block text-xs text-muted">
            {figure.hint}
          </span>
        )}
      </span>
      <span
        className={cn(
          'shrink-0 text-2xl font-semibold tracking-[-0.02em] tnum',
          tone?.value ?? 'text-ink-strong',
        )}
      >
        {value}
      </span>
    </>
  )

  if (to === null) {
    return <li className={cn('flex items-center gap-3 px-6 py-4', tone?.row)}>{content}</li>
  }

  return (
    <li>
      {/* The name is "label: count"; the hint is its description, so a screen
          reader hears the figure first instead of a run-on of three spans. */}
      <Link
        to={to}
        aria-label={`${item.label}: ${value}`}
        aria-describedby={figure ? hintId : undefined}
        className={cn(
          'group flex items-center gap-3 px-6 py-4 transition-colors duration-150 focus-visible:outline-offset-[-3px]',
          tone?.row ?? 'hover:bg-surface-sunken',
        )}
      >
        {content}
        <ChevronRight
          size={20}
          aria-hidden="true"
          className="shrink-0 text-muted transition-colors duration-150 group-hover:text-ink-strong"
        />
      </Link>
    </li>
  )
}
