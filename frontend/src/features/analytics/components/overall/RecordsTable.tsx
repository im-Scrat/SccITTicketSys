import { ArrowDown, ArrowUp } from 'lucide-react'
import { useState } from 'react'
import { ErrorState, Pagination, Skeleton } from '@/components/ui'
import { useOverallRecords } from '../../hooks/queries'
import type { OverallParams } from '../../types'
import { SectionTable } from './SectionTable'

const SORT_LABELS: Record<string, string> = {
  reported: 'Reported',
  ticket: 'Ticket',
  completed: 'Completed',
  type: 'Type',
  replaced: 'Replaced',
  code: 'Code',
  registered: 'Registered',
  warranty: 'Warranty',
  problems: 'Problems',
}

/**
 * A server-paginated record table (Phase 2.10 R4): 25 rows a page, sorted by
 * the server's allow-list, over exactly the population its section counts —
 * its total equals the section's figure. Each row opens the record.
 */
export function RecordsTable({
  table,
  title,
  params,
}: {
  table: string
  title: string
  params: OverallParams
}) {
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<string | undefined>(undefined)
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc')
  const records = useOverallRecords(table, params, { page, sort, direction })
  const data = records.data

  if (records.isError && !data) {
    return (
      <ErrorState
        title={`${title} could not be loaded`}
        description="Try again in a moment."
        onRetry={() => void records.refetch()}
        retrying={records.isFetching}
      />
    )
  }

  if (!data) return <Skeleton className="h-48" />

  const from = data.meta.total === 0 ? null : (data.meta.page - 1) * data.meta.per_page + 1
  const to =
    data.meta.total === 0 ? null : Math.min(data.meta.page * data.meta.per_page, data.meta.total)

  return (
    <section aria-label={title} className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm text-muted">Sort by</span>
        {data.meta.sorts.map((key) => {
          const active = data.meta.sort === key
          return (
            <button
              key={key}
              type="button"
              aria-pressed={active}
              onClick={() => {
                if (active) setDirection(direction === 'desc' ? 'asc' : 'desc')
                else {
                  setSort(key)
                  setDirection('desc')
                }
                setPage(1)
              }}
              className={
                active
                  ? 'inline-flex h-10 items-center gap-1 rounded-md border-2 border-primary bg-primary-subtle px-3 text-sm font-semibold text-primary-strong'
                  : 'inline-flex h-10 items-center gap-1 rounded-md border-2 border-control-border bg-surface px-3 text-sm text-ink hover:bg-surface-sunken'
              }
            >
              {SORT_LABELS[key] ?? key}
              {active &&
                (data.meta.direction === 'desc' ? (
                  <ArrowDown size={14} aria-label="descending" />
                ) : (
                  <ArrowUp size={14} aria-label="ascending" />
                ))}
            </button>
          )
        })}
      </div>
      <div className={records.isFetching ? 'opacity-60 transition-opacity' : 'transition-opacity'}>
        <SectionTable
          title={`${title} (${data.meta.total.toLocaleString('en-GB')})`}
          columns={data.data.columns}
          rows={data.data.rows}
          links={data.data.links}
        />
      </div>
      {data.meta.last_page > 1 && (
        <Pagination
          page={data.meta.page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          from={from}
          to={to}
          onPage={setPage}
        />
      )}
    </section>
  )
}
