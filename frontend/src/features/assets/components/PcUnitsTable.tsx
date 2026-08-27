import { ChevronRight } from 'lucide-react'
import { Badge, SortableTh, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { PcSortColumn, PcUnitListItem } from '../types'

interface PcUnitsTableProps {
  pcUnits: PcUnitListItem[]
  sort: PcSortColumn
  direction: 'asc' | 'desc'
  onSort: (column: PcSortColumn) => void
  onOpen: (id: string) => void
}

/**
 * The PC-unit directory table, with the same wide/narrow split as
 * {@link AssetsTable} — a real table from `md` up, labelled blocks below.
 */
export function PcUnitsTable({ pcUnits, sort, direction, onSort, onOpen }: PcUnitsTableProps) {
  return (
    <>
      <div className="hidden md:block">
        <Table>
          <THead>
            <Tr>
              <SortableTh
                label="Unit code"
                column="unit_code"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="PC name"
                column="pc_name"
                sort={sort}
                direction={direction}
                onSort={onSort}
              />
              <SortableTh
                label="Hostname"
                column="hostname"
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
              <Th className="text-right">Components</Th>
              <Th className="w-14">
                <span className="sr-only">Open</span>
              </Th>
            </Tr>
          </THead>
          <TBody>
            {pcUnits.map((unit) => (
              <Tr key={unit.id} className={cn(unit.archived && 'opacity-70')}>
                <Td className="font-mono text-sm font-semibold text-ink-strong">
                  {unit.unit_code}
                  {unit.archived && (
                    <Badge tone="neutral" className="ml-2">
                      Archived
                    </Badge>
                  )}
                </Td>
                <Td>
                  <button
                    type="button"
                    onClick={() => onOpen(unit.id)}
                    className="text-left font-semibold text-primary-strong underline-offset-4 hover:underline"
                  >
                    {unit.pc_name}
                  </button>
                  {unit.brand && (
                    <span className="block text-xs text-muted">
                      {unit.brand}
                      {unit.model ? ` · ${unit.model}` : ''}
                    </span>
                  )}
                </Td>
                <Td className="font-mono text-sm text-muted">{unit.hostname ?? '—'}</Td>
                <Td>
                  <Badge tone="neutral">{unit.status_label}</Badge>
                </Td>
                <Td className="text-sm">
                  {unit.room ? (
                    <>
                      <span className="block text-ink">{unit.room.name}</span>
                      {unit.building && (
                        <span className="block text-xs text-muted">{unit.building.name}</span>
                      )}
                    </>
                  ) : (
                    <span className="text-muted">Unassigned</span>
                  )}
                </Td>
                <Td className="text-right text-sm tnum">{unit.components_count}</Td>
                <Td>
                  <button
                    type="button"
                    onClick={() => onOpen(unit.id)}
                    className="flex size-12 items-center justify-center rounded-md text-muted hover:bg-surface-sunken hover:text-ink"
                    aria-label={`Open ${unit.unit_code}`}
                  >
                    <ChevronRight size={28} aria-hidden="true" />
                  </button>
                </Td>
              </Tr>
            ))}
          </TBody>
        </Table>
      </div>

      <ul className="flex flex-col gap-4 p-4 md:hidden">
        {pcUnits.map((unit) => (
          <li
            key={unit.id}
            className={cn(
              'rounded-lg border-2 border-border bg-surface p-5',
              unit.archived && 'opacity-70',
            )}
          >
            <div className="flex items-start justify-between gap-4">
              <button
                type="button"
                onClick={() => onOpen(unit.id)}
                className="text-left text-base font-bold text-primary-strong underline-offset-4 hover:underline"
              >
                {unit.pc_name}
              </button>
              <Badge tone="neutral">{unit.status_label}</Badge>
            </div>
            <dl className="mt-4 grid gap-3">
              <Row label="Unit code" value={unit.unit_code} mono />
              <Row label="Hostname" value={unit.hostname ?? '—'} mono />
              <Row
                label="Room"
                value={
                  unit.room
                    ? `${unit.building ? `${unit.building.name} · ` : ''}${unit.room.name}`
                    : 'Unassigned'
                }
              />
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
