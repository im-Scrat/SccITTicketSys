import { AlertTriangle, CheckCheck, Clock, Inbox, TimerReset, UserX } from 'lucide-react'
import { StatCard } from '@/components/ui'
import type { TicketDashboard } from '../types'

interface TicketMetricsCardsProps {
  dashboard: TicketDashboard
  /** Applying a tile writes its filter to the URL, so the view is shareable. */
  onFilter: (changes: Record<string, string | number | null>) => void
  activeParams: URLSearchParams
}

/**
 * The administrator's triage figures (SRS FR-DSH-003).
 *
 * **Every tile is a filter.** A number an operator cannot act on is decoration —
 * "7 overdue" is only useful if pressing it produces the seven. Each tile writes
 * its filter into the URL rather than local state, so the resulting view can be
 * refreshed, bookmarked and pasted to whoever needs to deal with it.
 *
 * The figures come from `TicketMetrics`, the same service the role dashboard
 * uses, so this page and the dashboard can never disagree about the backlog.
 */
export function TicketMetricsCards({ dashboard, onFilter, activeParams }: TicketMetricsCardsProps) {
  const { summary } = dashboard

  const isActive = (key: string, value: string) => activeParams.get(key) === value

  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
      <StatCard
        label="Open backlog"
        value={summary.open_backlog}
        icon={<Inbox size={32} aria-hidden="true" />}
        hint="Everything not yet closed or cancelled"
        onClick={() => onFilter({ status: null, technician: null, breached: null, page: null })}
      />

      <StatCard
        label="Unassigned"
        value={summary.unassigned}
        tone={summary.unassigned > 0 ? 'warning' : 'neutral'}
        icon={<UserX size={32} aria-hidden="true" />}
        hint="Nobody is working these yet"
        active={isActive('technician', 'unassigned')}
        onClick={() => onFilter({ technician: 'unassigned', page: null })}
      />

      <StatCard
        label="Overdue"
        value={summary.breached}
        tone={summary.breached > 0 ? 'danger' : 'success'}
        icon={<AlertTriangle size={32} aria-hidden="true" />}
        hint="Past the resolution deadline"
        active={isActive('breached', '1')}
        onClick={() => onFilter({ breached: '1', page: null })}
      />

      <StatCard
        label="Due soon"
        value={summary.at_risk}
        tone={summary.at_risk > 0 ? 'warning' : 'neutral'}
        icon={<Clock size={32} aria-hidden="true" />}
        hint={`Within ${summary.lead_hours} hours of the deadline`}
      />

      <StatCard
        label="Awaiting confirmation"
        value={summary.awaiting_confirmation}
        icon={<CheckCheck size={32} aria-hidden="true" />}
        hint="Resolved — the reporter has not confirmed yet"
        active={isActive('awaiting_confirmation', '1')}
        onClick={() => onFilter({ awaiting_confirmation: '1', page: null })}
      />

      <StatCard
        label="Automatic closure"
        value={`${dashboard.auto_close_days} days`}
        icon={<TimerReset size={32} aria-hidden="true" />}
        hint="A resolved ticket closes itself after this — an administrator can still act at any point"
      />
    </div>
  )
}
