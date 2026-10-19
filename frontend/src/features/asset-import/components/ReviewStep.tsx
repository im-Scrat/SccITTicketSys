import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Button,
  EmptyState,
  Field,
  Input,
  LiveRegion,
  Pagination,
  Select,
  Skeleton,
  Tabs,
} from '@/components/ui'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { ListChecks } from 'lucide-react'
import { useBulkRows } from '../hooks/mutations'
import { useImportCatalog, useImportLocations, useImportRows } from '../hooks/queries'
import { blockingCount, plural } from '../lib/steps'
import type { AssetImport, ImportRow, RowStatus } from '../types'
import { CatalogProposalList } from './CatalogProposalList'
import { ImportActionBar } from './ImportActionBar'
import { LocationResolver } from './LocationResolver'
import { RowIssuePanel } from './RowIssuePanel'
import { ImportRowsTable } from './ImportRowsTable'

const STATUS_FILTERS: Array<{ value: '' | RowStatus; label: string }> = [
  { value: '', label: 'All rows' },
  { value: 'needs_review', label: 'Needs a decision' },
  { value: 'invalid', label: 'Invalid' },
  { value: 'ready', label: 'Ready' },
  { value: 'duplicate', label: 'Duplicates' },
  { value: 'excluded', label: 'Excluded' },
]

/**
 * Review and fix (Phase 2.10 R6, plan §3.4): the rows, the location
 * decisions and the catalog proposals. Every change is checked again on the
 * server and the counts here follow it; Confirm stays closed, with the reason
 * in words, while any row still needs a decision.
 */
export function ReviewStep({ item, onContinue }: { item: AssetImport; onContinue: () => void }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') ?? 'rows'
  const [status, setStatus] = useState<'' | RowStatus>(() =>
    blockingCount(item) > 0 ? (item.row_counts?.invalid ? 'invalid' : 'needs_review') : '',
  )
  const [type, setType] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [open, setOpen] = useState<ImportRow | null>(null)
  const [announcement, setAnnouncement] = useState('')
  const debounced = useDebouncedValue(search, 300)

  const query = {
    status: status || undefined,
    type: type || undefined,
    search: debounced || undefined,
    page,
    per_page: 25,
  }
  const rows = useImportRows(item.id, query, tab === 'rows')
  const locations = useImportLocations(item.id)
  const catalog = useImportCatalog(item.id, item.entity_mode !== 'pc_units')
  const bulk = useBulkRows(item.id)

  const counts = item.row_counts
  const blocking = blockingCount(item)
  const pendingLocations = (locations.data ?? []).filter((l) => l.resolution === 'pending').length
  const pendingCatalog = (catalog.data ?? []).filter((e) => e.decision === 'pending').length
  const total = item.plan?.total ?? 0

  const setTab = (next: string) => {
    const params = new URLSearchParams(searchParams)
    params.set('tab', next)
    setSearchParams(params, { replace: true })
  }

  const reason =
    blocking > 0
      ? `${plural(blocking, 'row')} still ${blocking === 1 ? 'needs' : 'need'} a decision. Fix ${blocking === 1 ? 'it' : 'them'} or exclude ${blocking === 1 ? 'it' : 'them'}.`
      : total === 0
        ? 'There is nothing to import: every row is a duplicate or excluded.'
        : null

  const onSaved = (row: ImportRow) => {
    setAnnouncement(`Row ${row.row_number} is now ${row.status_label.toLowerCase()}.`)
    // Move to the next row on this page that still needs a decision.
    const list = rows.data?.data ?? []
    const next = list.find(
      (r) =>
        r.row_number > row.row_number && (r.status === 'invalid' || r.status === 'needs_review'),
    )
    setOpen(
      row.status === 'ready' || row.status === 'excluded' || row.status === 'duplicate'
        ? (next ?? null)
        : row,
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <LiveRegion message={announcement} />
      <Tabs
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'rows', label: 'Rows', count: blocking },
          { value: 'locations', label: 'Locations', count: pendingLocations },
          ...(item.entity_mode !== 'pc_units'
            ? [{ value: 'catalog', label: 'Catalog', count: pendingCatalog }]
            : []),
        ]}
      />

      {tab === 'locations' && <LocationResolver importId={item.id} />}
      {tab === 'catalog' && <CatalogProposalList importId={item.id} />}

      {tab === 'rows' && (
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-end gap-3">
            <label className="flex min-w-[14rem] flex-col gap-2">
              <span className="text-sm font-semibold text-ink">Show</span>
              <Select
                value={status}
                onChange={(e) => {
                  setStatus(e.target.value as '' | RowStatus)
                  setPage(1)
                  setSelected(new Set())
                }}
              >
                {STATUS_FILTERS.map((f) => (
                  <option key={f.value} value={f.value}>
                    {f.label}
                    {counts
                      ? ` (${(f.value === '' ? item.total_rows : counts[f.value]).toLocaleString()})`
                      : ''}
                  </option>
                ))}
              </Select>
            </label>
            {item.entity_mode === 'mixed' && (
              <label className="flex min-w-[12rem] flex-col gap-2">
                <span className="text-sm font-semibold text-ink">Kind</span>
                <Select
                  value={type}
                  onChange={(e) => {
                    setType(e.target.value)
                    setPage(1)
                  }}
                >
                  <option value="">PC units and equipment</option>
                  <option value="pc_unit">PC units</option>
                  <option value="asset">Other equipment</option>
                </Select>
              </label>
            )}
            <div className="min-w-[16rem] flex-1">
              <Field label="Search rows">
                <Input
                  type="search"
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value)
                    setPage(1)
                  }}
                  placeholder="Unit code, tag, serial, name or row number"
                />
              </Field>
            </div>
          </div>

          {selected.size > 0 && (
            <div
              className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-surface-sunken p-3"
              role="region"
              aria-label="Selected rows"
            >
              <span className="text-sm font-semibold text-ink">
                {plural(selected.size, 'row')} selected
              </span>
              <Button
                size="sm"
                variant="secondary"
                disabled={bulk.isPending}
                onClick={() =>
                  bulk.mutate(
                    { rows: [...selected], exclude: true },
                    { onSuccess: () => setSelected(new Set()) },
                  )
                }
              >
                Exclude
              </Button>
              <Button
                size="sm"
                variant="ghost"
                disabled={bulk.isPending}
                onClick={() =>
                  bulk.mutate(
                    { rows: [...selected], exclude: false },
                    { onSuccess: () => setSelected(new Set()) },
                  )
                }
              >
                Include again
              </Button>
            </div>
          )}

          {rows.isLoading ? (
            <Skeleton className="h-64 w-full" />
          ) : rows.data && rows.data.data.length > 0 ? (
            <div
              className={rows.isFetching ? 'opacity-60 transition-opacity' : undefined}
              aria-busy={rows.isFetching}
            >
              <ImportRowsTable
                rows={rows.data.data}
                mixed={item.entity_mode === 'mixed'}
                selected={selected}
                onToggle={(n) =>
                  setSelected((current) => {
                    const next = new Set(current)
                    if (next.has(n)) next.delete(n)
                    else next.add(n)
                    return next
                  })
                }
                onToggleAll={(checked) =>
                  setSelected(
                    checked ? new Set(rows.data.data.map((r) => r.row_number)) : new Set(),
                  )
                }
                onOpen={setOpen}
              />
              <Pagination
                page={rows.data.meta.current_page}
                lastPage={rows.data.meta.last_page}
                total={rows.data.meta.total}
                from={rows.data.meta.from}
                to={rows.data.meta.to}
                onPage={setPage}
              />
            </div>
          ) : (
            <EmptyState
              icon={<ListChecks size={32} aria-hidden="true" />}
              title={status ? 'No rows like that' : 'No rows'}
              description={
                status === 'invalid' || status === 'needs_review'
                  ? 'Nothing here needs a decision.'
                  : 'Try another filter.'
              }
            />
          )}
        </div>
      )}

      <RowIssuePanel
        item={item}
        row={open}
        onClose={() => setOpen(null)}
        onSaved={onSaved}
        onGoTo={(next) => {
          setOpen(null)
          setTab(next)
        }}
      />

      <ImportActionBar reason={reason}>
        <Button onClick={onContinue} disabled={reason !== null}>
          Continue to confirm
        </Button>
      </ImportActionBar>
    </div>
  )
}
