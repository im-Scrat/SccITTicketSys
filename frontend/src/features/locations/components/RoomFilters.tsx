import { Select } from '@/components/ui'
import { roomTypeValues } from '../schemas'
import type { RoomParams, RoomType } from '../types'

const ROOM_TYPE_LABELS: Record<RoomType, string> = {
  laboratory: 'Laboratory',
  office: 'Office',
  storage: 'Storage',
  server_room: 'Server room',
  faculty_room: 'Faculty room',
  library: 'Library',
  other: 'Other',
}

interface RoomFiltersProps {
  params: RoomParams
  onChange: (patch: Partial<RoomParams>) => void
}

/** Type / availability / archive filters for the room directory. */
export function RoomFilters({ params, onChange }: RoomFiltersProps) {
  return (
    <>
      <Select
        aria-label="Filter by room type"
        value={params.room_type ?? 'all'}
        onChange={(event) =>
          onChange({ room_type: event.target.value as RoomType | 'all', page: 1 })
        }
      >
        <option value="all">All types</option>
        {roomTypeValues.map((value) => (
          <option key={value} value={value}>
            {ROOM_TYPE_LABELS[value]}
          </option>
        ))}
      </Select>

      <Select
        aria-label="Filter by availability"
        value={params.active ?? 'all'}
        onChange={(event) =>
          onChange({ active: event.target.value as RoomParams['active'], page: 1 })
        }
      >
        <option value="all">Any availability</option>
        <option value="active">In service</option>
        <option value="inactive">Out of service</option>
      </Select>

      <Select
        aria-label="Archived rooms"
        value={params.trashed ?? 'without'}
        onChange={(event) =>
          onChange({ trashed: event.target.value as RoomParams['trashed'], page: 1 })
        }
      >
        <option value="without">Hide archived</option>
        <option value="with">Include archived</option>
        <option value="only">Archived only</option>
      </Select>
    </>
  )
}
