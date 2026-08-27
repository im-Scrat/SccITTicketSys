import { ChevronRight } from 'lucide-react'
import { Badge, SortableTh, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import type { BuildingListItem, BuildingSortColumn } from '../types'

interface BuildingsTableProps {
  buildings: BuildingListItem[]
  sort: BuildingSortColumn
  direction: 'asc' | 'desc'
  onSort: (column: BuildingSortColumn) => void
  onRowClick: (id: string) => void
}

export function BuildingsTable({
  buildings,
  sort,
  direction,
  onSort,
  onRowClick,
}: BuildingsTableProps) {
  return (
    <Table>
      <THead>
        <Tr>
          <SortableTh
            label="Building"
            column="name"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Code"
            column="code"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="hidden sm:table-cell"
          />
          <Th className="hidden md:table-cell">Address</Th>
          <SortableTh
            label="Floors"
            column="floors_count"
            sort={sort}
            direction={direction}
            onSort={onSort}
            numeric
          />
          <SortableTh
            label="Rooms"
            column="rooms_count"
            sort={sort}
            direction={direction}
            onSort={onSort}
            numeric
          />
          <Th className="w-8" aria-label="Open" />
        </Tr>
      </THead>
      <TBody>
        {buildings.map((building) => (
          <Tr key={building.id} onClick={() => onRowClick(building.id)}>
            <Td>
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-ink-strong">{building.name}</span>
                {building.archived && <Badge tone="outline">Archived</Badge>}
                {!building.archived && !building.is_active && (
                  <Badge tone="warning">Inactive</Badge>
                )}
              </div>
              <div className="mt-0.5 font-mono text-xs text-muted sm:hidden">{building.code}</div>
            </Td>
            <Td className="hidden font-mono text-xs text-muted sm:table-cell">{building.code}</Td>
            <Td className="hidden max-w-xs truncate text-muted md:table-cell">
              {building.address ?? '—'}
            </Td>
            <Td className="text-right text-muted tnum">{building.floors_count}</Td>
            <Td className="text-right text-ink tnum">{building.rooms_count}</Td>
            <Td className="w-8 text-faint">
              <ChevronRight size={16} aria-hidden="true" />
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}
