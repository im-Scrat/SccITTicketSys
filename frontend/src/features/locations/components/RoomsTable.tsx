import { ChevronRight } from 'lucide-react'
import { Badge, SortableTh, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import type { RoomListItem, RoomSortColumn } from '../types'
import { RoomTypeBadge } from './RoomTypeBadge'

interface RoomsTableProps {
  rooms: RoomListItem[]
  sort: RoomSortColumn
  direction: 'asc' | 'desc'
  onSort: (column: RoomSortColumn) => void
  onRowClick: (id: string) => void
}

/**
 * The room directory. Location context (building · floor) travels with each row
 * so a filtered or searched result still says *where* the room is; counts are
 * right-aligned and tabular so a column reads down.
 */
export function RoomsTable({ rooms, sort, direction, onSort, onRowClick }: RoomsTableProps) {
  return (
    <Table>
      <THead>
        <Tr>
          {/* Wide enough that a typical room name stays on one line. */}
          <SortableTh
            label="Room"
            column="name"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="min-w-44"
          />
          <SortableTh
            label="Type"
            column="room_type"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="hidden sm:table-cell"
          />
          <SortableTh
            label="Building"
            column="building"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="hidden md:table-cell"
          />
          <SortableTh
            label="Floor"
            column="floor_number"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="hidden lg:table-cell"
            numeric
          />
          <SortableTh
            label="Capacity"
            column="capacity"
            sort={sort}
            direction={direction}
            onSort={onSort}
            className="hidden lg:table-cell"
            numeric
          />
          <SortableTh
            label="PCs"
            column="pc_units_count"
            sort={sort}
            direction={direction}
            onSort={onSort}
            numeric
          />
          <Th className="w-8" aria-label="Open" />
        </Tr>
      </THead>
      <TBody>
        {rooms.map((room) => (
          <Tr key={room.id} onClick={() => onRowClick(room.id)}>
            <Td>
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-ink-strong">{room.name}</span>
                <span className="font-mono text-xs text-muted">{room.code}</span>
                {room.archived && <Badge tone="outline">Archived</Badge>}
                {!room.archived && !room.is_active && <Badge tone="warning">Inactive</Badge>}
              </div>
              <div className="mt-0.5 text-xs text-muted sm:hidden">
                {room.room_type_label}
                {room.building?.name ? ` · ${room.building.name}` : ''}
              </div>
            </Td>
            <Td className="hidden whitespace-nowrap sm:table-cell">
              <RoomTypeBadge type={room.room_type} label={room.room_type_label} />
            </Td>
            <Td className="hidden whitespace-nowrap text-muted md:table-cell">
              {room.building?.name ?? '—'}
            </Td>
            <Td className="hidden text-right text-muted tnum lg:table-cell">
              {room.floor?.floor_number ?? '—'}
            </Td>
            <Td className="hidden text-right text-muted tnum lg:table-cell">
              {room.capacity ?? '—'}
            </Td>
            <Td className="text-right text-ink tnum">{room.pc_units_count}</Td>
            <Td className="w-8 text-faint">
              <ChevronRight size={16} aria-hidden="true" />
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}
