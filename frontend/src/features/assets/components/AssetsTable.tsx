import { ChevronRight } from 'lucide-react'
import { Badge, Checkbox, SortableTh, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { cn } from '@/lib/cn'
import { formatDate } from '@/lib/datetime'
import type { AssetListItem, AssetSortColumn } from '../types'
import { AssetStatusBadge } from './AssetStatusBadge'

interface AssetsTableProps {
  assets: AssetListItem[]
  sort: AssetSortColumn
  direction: 'asc' | 'desc'
  onSort: (column: AssetSortColumn) => void
  onOpen: (id: string) => void
  /** Bulk selection — the UI foundation for a later phase's bulk actions. */
  selected: Set<string>
  onToggle: (id: string) => void
  onToggleAll: () => void
}

/**
 * The asset directory table.
 *
 * Two readings of the same rows, chosen by viewport: a real `<table>` from `md`
 * up, and a stacked card list below it. A data table squeezed onto a phone at
 * 20px type is unreadable, and horizontally scrolling one is worse — so the
 * narrow layout restates each row as a labelled block rather than shrinking it.
 * Both render from the same array, so they cannot disagree.
 */
export function AssetsTable({
  assets,
  sort,
  direction,
  onSort,
  onOpen,
  selected,
  onToggle,
  onToggleAll,
}: AssetsTableProps) {
  const allSelected = assets.length > 0 && assets.every((asset) => selected.has(asset.id))

  return (
    <>
      {/* Wide: the table. */}
      <div className="hidden md:block">
        <Table>
          <THead>
            <Tr>
              <Th className="w-14">
                <Checkbox
                  checked={allSelected}
                  onChange={onToggleAll}
                  label="Select all assets on this page"
                  labelHidden
                />
              </Th>
              <SortableTh
                label="Asset tag"
                column="asset_tag"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Name"
                column="name"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Category"
                column="category"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Status"
                column="status"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Room"
                column="room"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Custodian"
                column="technician"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Warranty"
                column="warranty_expiration"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <Th className="w-14">
                <span className="sr-only">Open</span>
              </Th>
            </Tr>
          </THead>
          <TBody>
            {assets.map((asset) => (
              <Tr key={asset.id} className={cn(asset.archived && 'opacity-70')}>
                <Td>
                  <Checkbox
                    checked={selected.has(asset.id)}
                    onChange={() => onToggle(asset.id)}
                    label={`Select ${asset.asset_tag}`}
                    labelHidden
                  />
                </Td>
                <Td className="font-mono text-sm font-semibold text-ink-strong">
                  {asset.asset_tag}
                  {asset.archived && (
                    <Badge tone="neutral" className="ml-2">
                      Archived
                    </Badge>
                  )}
                </Td>
                <Td>
                  <button
                    type="button"
                    onClick={() => onOpen(asset.id)}
                    className="text-left font-semibold text-primary-strong underline-offset-4 hover:underline"
                  >
                    {asset.name}
                  </button>
                  {asset.manufacturer && (
                    <span className="block text-xs text-muted">
                      {asset.manufacturer}
                      {asset.model ? ` · ${asset.model}` : ''}
                    </span>
                  )}
                </Td>
                <Td className="text-sm text-muted">{asset.category_label ?? '—'}</Td>
                <Td>
                  <AssetStatusBadge label={asset.status_label} tone={asset.tone} />
                </Td>
                <Td className="text-sm">
                  {asset.room ? (
                    <>
                      <span className="block text-ink">{asset.room.name}</span>
                      {asset.building && (
                        <span className="block text-xs text-muted">{asset.building.name}</span>
                      )}
                    </>
                  ) : (
                    <span className="text-muted">Unassigned</span>
                  )}
                </Td>
                <Td className="text-sm">
                  {asset.technician ? asset.technician.name : <span className="text-muted">—</span>}
                </Td>
                <Td className="text-sm tnum">
                  <WarrantyCell asset={asset} />
                </Td>
                <Td>
                  <button
                    type="button"
                    onClick={() => onOpen(asset.id)}
                    className="flex size-12 items-center justify-center rounded-md text-muted hover:bg-surface-sunken hover:text-ink"
                    aria-label={`Open ${asset.asset_tag}`}
                  >
                    <ChevronRight size={28} aria-hidden="true" />
                  </button>
                </Td>
              </Tr>
            ))}
          </TBody>
        </Table>
      </div>

      {/* Narrow: the same rows as labelled blocks. */}
      <ul className="flex flex-col gap-4 md:hidden">
        {assets.map((asset) => (
          <li
            key={asset.id}
            className={cn(
              'rounded-lg border-2 border-border bg-surface p-5',
              asset.archived && 'opacity-70',
            )}
          >
            <div className="flex items-start justify-between gap-4">
              <div className="flex items-center gap-3">
                <Checkbox
                  checked={selected.has(asset.id)}
                  onChange={() => onToggle(asset.id)}
                  label={`Select ${asset.asset_tag}`}
                  labelHidden
                />
                <button
                  type="button"
                  onClick={() => onOpen(asset.id)}
                  className="text-left text-base font-bold text-primary-strong underline-offset-4 hover:underline"
                >
                  {asset.name}
                </button>
              </div>
              <AssetStatusBadge label={asset.status_label} tone={asset.tone} />
            </div>

            <dl className="mt-4 grid gap-3">
              <Row label="Asset tag" value={asset.asset_tag} mono />
              <Row label="Category" value={asset.category_label ?? '—'} />
              <Row
                label="Room"
                value={
                  asset.room
                    ? `${asset.building ? `${asset.building.name} · ` : ''}${asset.room.name}`
                    : 'Unassigned'
                }
              />
              <Row label="Custodian" value={asset.technician?.name ?? '—'} />
            </dl>
          </li>
        ))}
      </ul>
    </>
  )
}

function Row({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2">
      <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{label}</dt>
      <dd className={cn('text-sm text-ink', mono && 'font-mono')}>{value}</dd>
    </div>
  )
}

/**
 * The warranty cell reads as a date plus a plain-language urgency note. The
 * server computes `days_remaining`, so the client never disagrees with the
 * dashboard about what "expiring soon" means.
 */
function WarrantyCell({ asset }: { asset: AssetListItem }) {
  if (!asset.warranty_expiration) {
    return <span className="text-muted">—</span>
  }

  const days = asset.warranty_days_remaining

  if (days !== null && days < 0) {
    return (
      <span className="font-semibold text-danger-strong">
        Lapsed {formatDate(asset.warranty_expiration)}
      </span>
    )
  }

  return (
    <span className={cn(days !== null && days <= 90 && 'font-semibold text-warning-strong')}>
      {formatDate(asset.warranty_expiration)}
      {days !== null && days <= 90 && <span className="block text-xs">{days} days left</span>}
    </span>
  )
}
