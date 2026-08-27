import { Archive, ArrowRightLeft, Cpu, FileText, QrCode, Ticket, Wrench } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { Button, EmptyState, Skeleton } from '@/components/ui'
import { formatDateTime } from '@/lib/datetime'
import type { AssetKind } from '../api/assetsApi'
import { useAssetHistory } from '../hooks/queries'
import type { HistoryEntry } from '../types'

const ICONS: Record<HistoryEntry['type'], LucideIcon> = {
  activity: FileText,
  status: Archive,
  transfer: ArrowRightLeft,
  installation: Cpu,
  maintenance: Wrench,
  ticket: Ticket,
  qr: QrCode,
}

const TYPE_LABELS: Record<HistoryEntry['type'], string> = {
  activity: 'Change',
  status: 'Status',
  transfer: 'Transfer',
  installation: 'Component',
  maintenance: 'Maintenance',
  ticket: 'Ticket',
  qr: 'QR code',
}

/**
 * The unified asset timeline (SRS FR-AST-005).
 *
 * One chronological stream merged from seven tables, so "what has happened to
 * this machine?" is a single answer rather than seven tabs to interleave by
 * hand. Each entry is typed, and the type is shown as **text plus** an icon —
 * never the icon alone.
 *
 * Field-level changes are expanded inline as old → new pairs, because "Updated"
 * on its own does not answer the question anyone actually has.
 */
export function AssetHistoryTimeline({ kind, id }: { kind: AssetKind; id: string | undefined }) {
  // Timeline paging is local rather than in the URL: the tab and the directory
  // filters own the query string, and a deep link to "page 3 of this asset's
  // history" is not something anyone shares.
  const [page, setPage] = useState(1)
  const { data, isLoading } = useAssetHistory(kind, id, page)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        {Array.from({ length: 5 }).map((_, index) => (
          <Skeleton key={index} className="h-20 rounded-md" />
        ))}
      </div>
    )
  }

  if (!data || data.data.length === 0) {
    return (
      <EmptyState
        title="Nothing recorded yet"
        description="Changes, transfers, maintenance and QR events appear here as they happen."
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <ol className="flex flex-col">
        {data.data.map((entry) => {
          const Icon = ICONS[entry.type] ?? FileText
          return (
            <li key={entry.id} className="flex gap-5 border-b border-border py-5 last:border-b-0">
              <span
                className="mt-1 flex size-12 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-ink"
                aria-hidden="true"
              >
                <Icon size={26} />
              </span>

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                  <span className="text-xs font-semibold uppercase tracking-wide text-muted">
                    {TYPE_LABELS[entry.type]}
                  </span>
                  <span className="text-base font-semibold text-ink-strong">{entry.label}</span>
                </div>

                {entry.description && (
                  <p className="mt-1 text-sm text-muted measure">{entry.description}</p>
                )}

                <Changes properties={entry.properties} />

                <p className="mt-2 text-xs text-muted">
                  {entry.actor ? entry.actor.name : 'System'} · {formatDateTime(entry.at)}
                </p>
              </div>
            </li>
          )
        })}
      </ol>

      {data.last_page > 1 && (
        <div className="flex items-center justify-between gap-4">
          <Button
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage(page - 1)}
          >
            Previous
          </Button>
          <p className="text-sm text-muted tnum" aria-live="polite">
            Page {data.current_page} of {data.last_page}
          </p>
          <Button
            variant="secondary"
            size="sm"
            disabled={page >= data.last_page}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  )
}

/**
 * Render the old→new diff an update recorded. Anything that is not a
 * `{from,to}` pair is left alone — the audit payload is deliberately open-ended,
 * so this only formats what it recognises.
 */
function Changes({ properties }: { properties: Record<string, unknown> | null }) {
  const changes = properties?.changes

  if (!changes || typeof changes !== 'object') return null

  const entries = Object.entries(changes as Record<string, unknown>).filter(
    (entry): entry is [string, { from: unknown; to: unknown }] =>
      typeof entry[1] === 'object' && entry[1] !== null && 'to' in (entry[1] as object),
  )

  if (entries.length === 0) return null

  return (
    <dl className="mt-3 flex flex-col gap-1.5 rounded-md bg-surface-sunken p-4">
      {entries.map(([field, change]) => (
        <div key={field} className="flex flex-wrap items-baseline gap-2 text-sm">
          <dt className="font-semibold text-ink">{humanize(field)}</dt>
          <dd className="text-muted">
            <span className="line-through">{display(change.from)}</span>
            <span aria-hidden="true"> → </span>
            <span className="sr-only"> changed to </span>
            <span className="font-medium text-ink">{display(change.to)}</span>
          </dd>
        </div>
      ))}
    </dl>
  )
}

function humanize(field: string): string {
  return field.replace(/_/g, ' ').replace(/^./, (char) => char.toUpperCase())
}

function display(value: unknown): string {
  if (value === null || value === undefined || value === '') return 'empty'
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  return String(value)
}
