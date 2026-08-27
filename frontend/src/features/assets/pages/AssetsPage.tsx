import { Download, Plus, Upload } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  Alert,
  Button,
  EmptyState,
  Pagination,
  SearchInput,
  Select,
  Skeleton,
  Tabs,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { AssetFormDrawer } from '../components/AssetFormDrawer'
import { AssetsTable } from '../components/AssetsTable'
import { BulkActionBar } from '../components/BulkActionBar'
import { PcUnitFormDrawer } from '../components/PcUnitFormDrawer'
import { PcUnitsTable } from '../components/PcUnitsTable'
import { useAssetCatalog, useAssetsList, usePcUnitsList } from '../hooks/queries'
import type { AssetParams, AssetSortColumn, PcSortColumn, PcUnitParams } from '../types'

const PER_PAGE = 20

/**
 * The asset directory.
 *
 * **Filter state lives in the URL.** Every dashboard tile links here with a
 * query string, and a filtered view has to survive a refresh, a bookmark, the
 * back button and being pasted to a colleague. Keeping it in React state would
 * break all four, so `useSearchParams` is the source of truth and the component
 * derives its query from it rather than mirroring it.
 */
export default function AssetsPage() {
  useDocumentMeta({ title: 'Asset directory' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('assets.create')

  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState(params.get('tab') === 'pc-units' ? 'pc-units' : 'assets')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [creatingAsset, setCreatingAsset] = useState(false)
  const [creatingPc, setCreatingPc] = useState(false)

  const catalog = useAssetCatalog()

  /** The query the API receives, derived from the URL. */
  const assetParams = useMemo<AssetParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      condition: params.get('condition') ?? undefined,
      category: params.get('category') ?? undefined,
      building: params.get('building') ?? undefined,
      floor: params.get('floor') ?? undefined,
      room: params.get('room') ?? undefined,
      technician: params.get('technician') ?? undefined,
      supplier: params.get('supplier') ?? undefined,
      manufacturer: params.get('manufacturer') ?? undefined,
      warranty_expiring: params.get('warranty_expiring')
        ? Number(params.get('warranty_expiring'))
        : undefined,
      trashed: (params.get('trashed') as AssetParams['trashed']) ?? 'without',
      sort: (params.get('sort') as AssetSortColumn) ?? 'asset_tag',
      direction: (params.get('direction') as 'asc' | 'desc') ?? 'asc',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const pcParams = useMemo<PcUnitParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('pc_status') ?? undefined,
      building: params.get('building') ?? undefined,
      room: params.get('room') ?? undefined,
      trashed: (params.get('trashed') as PcUnitParams['trashed']) ?? 'without',
      sort: (params.get('pc_sort') as PcSortColumn) ?? 'unit_code',
      direction: (params.get('direction') as 'asc' | 'desc') ?? 'asc',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const assets = useAssetsList(assetParams, tab === 'assets')
  const pcUnits = usePcUnitsList(pcParams, tab === 'pc-units')

  /** Debounce the search box into the URL. */
  useEffect(() => {
    const timer = setTimeout(() => {
      const current = params.get('search') ?? ''
      if (current === search) return
      patch({ search: search || null, page: null })
    }, 300)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  /** Merge changes into the query string; null removes a key. */
  function patch(changes: Record<string, string | number | null>) {
    setParams(
      (previous) => {
        const next = new URLSearchParams(previous)
        for (const [key, value] of Object.entries(changes)) {
          if (value === null || value === '') next.delete(key)
          else next.set(key, String(value))
        }
        return next
      },
      { replace: true },
    )
    setSelected(new Set())
  }

  const onAssetSort = (column: AssetSortColumn) =>
    patch({
      sort: column,
      direction: assetParams.sort === column && assetParams.direction === 'asc' ? 'desc' : 'asc',
      page: null,
    })

  const onPcSort = (column: PcSortColumn) =>
    patch({
      pc_sort: column,
      direction: pcParams.sort === column && pcParams.direction === 'asc' ? 'desc' : 'asc',
      page: null,
    })

  const toggle = (id: string) =>
    setSelected((previous) => {
      const next = new Set(previous)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const rows = assets.data?.data ?? []

  const toggleAll = () =>
    setSelected((previous) =>
      previous.size === rows.length ? new Set() : new Set(rows.map((row) => row.id)),
    )

  const activeFilters = countActiveFilters(params)

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">Asset directory</h1>
          <p className="mt-2 text-base text-muted">
            Search the whole estate by tag, serial, name, room, brand, model, supplier, custodian or
            QR code.
          </p>
        </div>

        <div className="flex flex-wrap gap-3">
          {/*
            Import and export land in a later phase. Rendered disabled with an
            explanation rather than hidden: an operator who expects them should
            find out they are coming, not wonder whether they missed a menu.
          */}
          <Button variant="secondary" disabled title="Available in a later phase">
            <Upload size={32} aria-hidden="true" />
            Import
          </Button>
          <Button variant="secondary" disabled title="Available in a later phase">
            <Download size={32} aria-hidden="true" />
            Export
          </Button>
          {canCreate && (
            <Button
              onClick={() => (tab === 'assets' ? setCreatingAsset(true) : setCreatingPc(true))}
            >
              <Plus size={32} aria-hidden="true" />
              {tab === 'assets' ? 'Add asset' : 'Add PC unit'}
            </Button>
          )}
        </div>
      </header>

      <Tabs
        tabs={[
          { value: 'assets', label: 'Assets' },
          { value: 'pc-units', label: 'PC units' },
        ]}
        value={tab}
        onChange={(value) => {
          setTab(value)
          patch({ tab: value === 'pc-units' ? 'pc-units' : null, page: null })
        }}
      />

      <section className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder="Search assets…"
          aria-label="Search assets"
        />

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {tab === 'assets' ? (
            <>
              <FilterSelect
                label="Status"
                value={params.get('status') ?? ''}
                options={(catalog.data?.statuses ?? []).map((s) => ({
                  value: s.value,
                  label: s.label,
                }))}
                onChange={(value) => patch({ status: value || null, page: null })}
              />
              <FilterSelect
                label="Category"
                value={params.get('category') ?? ''}
                options={(catalog.data?.categories ?? []).map((c) => ({
                  value: c.value,
                  label: c.label,
                }))}
                onChange={(value) => patch({ category: value || null, page: null })}
              />
              <FilterSelect
                label="Condition"
                value={params.get('condition') ?? ''}
                options={(catalog.data?.conditions ?? []).map((c) => ({
                  value: c.value,
                  label: c.label,
                }))}
                onChange={(value) => patch({ condition: value || null, page: null })}
              />
            </>
          ) : (
            <FilterSelect
              label="Status"
              value={params.get('pc_status') ?? ''}
              options={(catalog.data?.pc_statuses ?? []).map((s) => ({
                value: s.value,
                label: s.label,
              }))}
              onChange={(value) => patch({ pc_status: value || null, page: null })}
            />
          )}

          <FilterSelect
            label="Archived"
            value={params.get('trashed') ?? 'without'}
            options={[
              { value: 'without', label: 'Hide archived' },
              { value: 'with', label: 'Include archived' },
              { value: 'only', label: 'Archived only' },
            ]}
            allowEmpty={false}
            onChange={(value) => patch({ trashed: value === 'without' ? null : value, page: null })}
          />
        </div>

        {activeFilters > 0 && (
          <div className="flex items-center gap-4">
            <p className="text-sm text-muted">
              {activeFilters} filter{activeFilters === 1 ? '' : 's'} applied
            </p>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSearch('')
                setParams(new URLSearchParams(), { replace: true })
              }}
            >
              Clear all filters
            </Button>
          </div>
        )}
      </section>

      {selected.size > 0 && (
        <BulkActionBar count={selected.size} onClear={() => setSelected(new Set())} />
      )}

      <section className="rounded-lg border-2 border-border bg-surface">
        {tab === 'assets' ? (
          assets.isError ? (
            <Alert tone="error" title="The directory could not be loaded">
              Refresh the page to try again.
            </Alert>
          ) : assets.isLoading ? (
            <TableSkeleton />
          ) : rows.length === 0 ? (
            <EmptyState
              title="No assets match this view"
              description="Adjust the search or filters above, or add a new asset."
            />
          ) : (
            <>
              <AssetsTable
                assets={rows}
                sort={assetParams.sort ?? 'asset_tag'}
                direction={assetParams.direction ?? 'asc'}
                onSort={onAssetSort}
                onOpen={(id) => navigate(`/app/assets/${id}`)}
                selected={selected}
                onToggle={toggle}
                onToggleAll={toggleAll}
              />
              {assets.data && (
                <Pagination
                  page={assets.data.meta.current_page}
                  lastPage={assets.data.meta.last_page}
                  total={assets.data.meta.total}
                  from={assets.data.meta.from}
                  to={assets.data.meta.to}
                  onPage={(page) => patch({ page })}
                />
              )}
            </>
          )
        ) : pcUnits.isError ? (
          <Alert tone="error" title="The PC directory could not be loaded">
            Refresh the page to try again.
          </Alert>
        ) : pcUnits.isLoading ? (
          <TableSkeleton />
        ) : (pcUnits.data?.data.length ?? 0) === 0 ? (
          <EmptyState
            title="No PC units match this view"
            description="Adjust the search or filters above, or add a new PC unit."
          />
        ) : (
          <>
            <PcUnitsTable
              pcUnits={pcUnits.data?.data ?? []}
              sort={pcParams.sort ?? 'unit_code'}
              direction={pcParams.direction ?? 'asc'}
              onSort={onPcSort}
              onOpen={(id) => navigate(`/app/assets/pc-units/${id}`)}
            />
            {pcUnits.data && (
              <Pagination
                page={pcUnits.data.meta.current_page}
                lastPage={pcUnits.data.meta.last_page}
                total={pcUnits.data.meta.total}
                from={pcUnits.data.meta.from}
                to={pcUnits.data.meta.to}
                onPage={(page) => patch({ page })}
              />
            )}
          </>
        )}
      </section>

      <AssetFormDrawer
        open={creatingAsset}
        onClose={() => setCreatingAsset(false)}
        onSaved={(id) => navigate(`/app/assets/${id}`)}
      />
      <PcUnitFormDrawer
        open={creatingPc}
        onClose={() => setCreatingPc(false)}
        onSaved={(id) => navigate(`/app/assets/pc-units/${id}`)}
      />
    </div>
  )
}

function FilterSelect({
  label,
  value,
  options,
  onChange,
  allowEmpty = true,
}: {
  label: string
  value: string
  options: Array<{ value: string; label: string }>
  onChange: (value: string) => void
  allowEmpty?: boolean
}) {
  return (
    <label className="flex flex-col gap-2">
      <span className="text-sm font-semibold text-ink">{label}</span>
      <Select value={value} onChange={(event) => onChange(event.target.value)}>
        {allowEmpty && <option value="">All</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </label>
  )
}

/** Everything except paging, sorting and the tab counts as a filter. */
function countActiveFilters(params: URLSearchParams): number {
  const ignored = new Set(['page', 'sort', 'pc_sort', 'direction', 'tab'])
  let count = 0
  params.forEach((_value, key) => {
    if (!ignored.has(key)) count += 1
  })
  return count
}

function TableSkeleton() {
  return (
    <div className="flex flex-col gap-3 p-6">
      {Array.from({ length: 8 }).map((_, index) => (
        <Skeleton key={index} className="h-14 rounded-md" />
      ))}
    </div>
  )
}
