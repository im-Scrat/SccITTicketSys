import {
  BarChart3,
  Bell,
  Boxes,
  ChevronDown,
  LayoutDashboard,
  Map,
  Search,
  Ticket,
  Wrench,
} from 'lucide-react'
import { cn } from '@/lib/cn'
import { Logo } from '@/components/ui/Logo'

/**
 * The hero "product shot": a faithful, in-code fragment of the SccIT operations
 * console (the ticket queue), built from the real design tokens rather than a
 * screenshot. This is the imagery for a self-hosted, CDN-free deployment — and
 * it doubles as living proof the design system holds up at density.
 *
 * Presentational only: exposed to assistive tech as a single labelled image.
 */

type TicketStatus = 'triage' | 'progress' | 'waiting' | 'resolved'
type Priority = 'High' | 'Medium' | 'Low'

interface Row {
  id: string
  title: string
  asset: string
  priority: Priority
  sla: string
  slaLow?: boolean
  status: TicketStatus
}

const rows: Row[] = [
  {
    id: '4821',
    title: 'Projector — no signal',
    asset: 'AV-PJ-118',
    priority: 'High',
    sla: '26m',
    slaLow: true,
    status: 'progress',
  },
  {
    id: '4820',
    title: 'Workstation won’t boot',
    asset: 'LAB2-PC-014',
    priority: 'High',
    sla: '1h 40m',
    status: 'triage',
  },
  {
    id: '4816',
    title: 'Switch port flapping',
    asset: 'NET-SW-07',
    priority: 'Medium',
    sla: '3h 05m',
    status: 'progress',
  },
  {
    id: '4814',
    title: 'Access point offline',
    asset: 'NET-AP-231',
    priority: 'Medium',
    sla: '5h 12m',
    status: 'waiting',
  },
  {
    id: '4809',
    title: 'Label printer jam',
    asset: 'PR-ZP-004',
    priority: 'Low',
    sla: 'Met',
    status: 'resolved',
  },
]

const statusMeta: Record<TicketStatus, { label: string; wash: string; text: string; dot: string }> =
  {
    triage: { label: 'Triage', wash: 'bg-info-subtle', text: 'text-info', dot: 'bg-info' },
    progress: {
      label: 'In progress',
      wash: 'bg-primary-subtle',
      text: 'text-primary-strong',
      dot: 'bg-primary',
    },
    waiting: {
      label: 'Waiting',
      wash: 'bg-warning-subtle',
      text: 'text-warning-strong',
      dot: 'bg-warning',
    },
    resolved: {
      label: 'Resolved',
      wash: 'bg-success-subtle',
      text: 'text-success-strong',
      dot: 'bg-success',
    },
  }

const priorityDot: Record<Priority, string> = {
  High: 'bg-warning',
  Medium: 'bg-info',
  Low: 'bg-faint',
}

const navItems = [
  { icon: LayoutDashboard, label: 'Overview' },
  { icon: Ticket, label: 'Tickets', active: true },
  { icon: Boxes, label: 'Assets' },
  { icon: Wrench, label: 'Maintenance' },
  { icon: Map, label: 'Floor plan' },
  { icon: BarChart3, label: 'Analytics' },
]

const kpis = [
  { label: 'Open backlog', value: '42' },
  { label: 'SLA compliance', value: '94.2%' },
  { label: 'MTTR', value: '4.6h' },
]

function Pill({ meta }: { meta: { label: string; wash: string; text: string; dot: string } }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
        meta.wash,
        meta.text,
      )}
    >
      <span className={cn('size-1.5 shrink-0 rounded-full', meta.dot)} />
      {meta.label}
    </span>
  )
}

export function ConsolePreview({ className }: { className?: string }) {
  return (
    <figure
      role="img"
      aria-label="The SccIT operations console showing an open-ticket queue with SLA timers, priorities, and live status."
      className={cn(
        'overflow-hidden rounded-lg border border-border bg-surface shadow-[var(--shadow-overlay-md)]',
        className,
      )}
    >
      {/* Top bar */}
      <div className="flex h-12 items-center gap-3 border-b border-border bg-surface px-3.5">
        <Logo withWordmark={false} size={20} />
        <span className="inline-flex items-center gap-1.5 rounded-sm border border-border bg-surface-sunken px-2 py-1 text-xs font-medium text-ink">
          Operations · HQ
          <ChevronDown size={13} className="text-muted" />
        </span>
        <div className="ml-auto flex items-center gap-2">
          <span className="hidden items-center gap-2 rounded-sm border border-border bg-surface-sunken px-2.5 py-1 text-xs text-muted sm:inline-flex">
            <Search size={13} />
            Search
            <kbd className="rounded border border-border bg-surface px-1 font-mono text-[10px] text-muted">
              ⌘K
            </kbd>
          </span>
          <span className="flex size-7 items-center justify-center rounded-sm text-muted">
            <Bell size={15} />
          </span>
          <span className="flex size-7 items-center justify-center rounded-full bg-primary-subtle text-[11px] font-semibold text-primary-strong">
            IT
          </span>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-[168px_1fr]">
        {/* Left rail (decorative — the whole figure is exposed as one image) */}
        <div className="hidden flex-col gap-0.5 border-r border-border bg-surface p-2.5 sm:flex">
          {navItems.map(({ icon: Icon, label, active }) => (
            <span
              key={label}
              className={cn(
                'flex items-center gap-2.5 rounded-sm px-2.5 py-1.5 text-[13px]',
                active ? 'bg-primary-subtle font-medium text-primary-strong' : 'text-muted',
              )}
            >
              <Icon size={15} className={active ? 'text-primary-strong' : 'text-faint'} />
              {label}
            </span>
          ))}
        </div>

        {/* Main panel */}
        <div className="min-w-0 p-3.5">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-ink-strong">Open tickets</h3>
            <span className="hidden items-center gap-1.5 rounded-sm border border-border px-2 py-0.5 text-[11px] text-muted sm:inline-flex">
              Sorted by SLA
            </span>
          </div>

          {/* KPI strip */}
          <div className="mb-3.5 grid grid-cols-3 gap-2">
            {kpis.map((kpi) => (
              <div
                key={kpi.label}
                className="rounded-md border border-border bg-surface-sunken px-2.5 py-2"
              >
                <p className="truncate text-[10.5px] font-medium text-muted">{kpi.label}</p>
                <p className="tnum mt-0.5 text-base font-semibold text-ink-strong">{kpi.value}</p>
              </div>
            ))}
          </div>

          {/* Ticket table. Fixed layout: the ticket column flexes and truncates,
              the rest size to content. Priority rides as a leading dot (with a
              title attr) to keep the queue readable at the hero's narrow width. */}
          <div className="overflow-hidden rounded-md border border-border">
            <table className="w-full table-fixed border-collapse text-left">
              <thead>
                <tr className="bg-surface-sunken text-[10.5px] uppercase tracking-[0.04em] text-muted">
                  <th className="px-2.5 py-1.5 font-medium">Ticket</th>
                  <th className="hidden w-[104px] px-2.5 py-1.5 font-medium md:table-cell">
                    Asset
                  </th>
                  <th className="w-[68px] px-2.5 py-1.5 text-right font-medium">SLA</th>
                  <th className="w-[112px] px-2.5 py-1.5 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="text-[12.5px]">
                {rows.map((row) => (
                  <tr key={row.id} className="border-t border-border">
                    <td className="px-2.5 py-2">
                      <div className="flex items-center gap-2">
                        <span
                          className={cn(
                            'size-1.5 shrink-0 rounded-full',
                            priorityDot[row.priority],
                          )}
                          title={`${row.priority} priority`}
                        />
                        <span className="slashed-zero shrink-0 font-mono text-[11px] text-muted">
                          #{row.id}
                        </span>
                        <span className="truncate text-ink">{row.title}</span>
                      </div>
                    </td>
                    <td className="hidden whitespace-nowrap px-2.5 py-2 md:table-cell">
                      <span className="slashed-zero font-mono text-[11px] text-muted">
                        {row.asset}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-2.5 py-2 text-right">
                      <span
                        className={cn(
                          'tnum text-[12px]',
                          row.slaLow ? 'font-medium text-warning-strong' : 'text-muted',
                        )}
                      >
                        {row.sla}
                      </span>
                    </td>
                    <td className="px-2.5 py-2">
                      <Pill meta={statusMeta[row.status]} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </figure>
  )
}
