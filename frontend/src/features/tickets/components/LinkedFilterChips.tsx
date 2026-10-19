import { FilterChips, type FilterChip } from '@/components/ui'
import { useReportOptions } from '@/features/analytics/hooks/queries'
import { useReportsAccess } from '@/features/analytics/hooks/useReportsAccess'

const ROOM_TYPE_LABELS: Record<string, string> = {
  laboratory: 'Laboratories',
  office: 'Offices',
}

/**
 * The filters a report figure's drill-down brought with it (Phase 2.10) —
 * reported dates, a room or room type, a problem area, "problems only" — which
 * the directory's own controls do not offer. Without these chips the list
 * would be filtered by things nobody can see; each one says what it is and
 * removes on its own.
 */
export function LinkedFilterChips({
  params,
  patch,
}: {
  params: URLSearchParams
  patch: (changes: Record<string, string | number | null>) => void
}) {
  const { canView } = useReportsAccess()
  const needsNames = params.has('room') || params.has('building')
  const options = useReportOptions(canView && needsNames)
  const chips: FilterChip[] = []

  const from = params.get('reported_from')
  const to = params.get('reported_to')
  if (from || to) {
    chips.push({
      key: 'reported',
      label: `Reported ${from && to ? (from === to ? `on ${from}` : `${from} to ${to}`) : from ? `from ${from}` : `up to ${to}`}`,
      onRemove: () => patch({ reported_from: null, reported_to: null, page: null }),
    })
  }

  const room = params.get('room')
  if (room) {
    chips.push({
      key: 'room',
      label: `Room: ${options.data?.rooms.find((r) => r.id === room)?.label ?? 'selected room'}`,
      onRemove: () => patch({ room: null, page: null }),
    })
  }

  const building = params.get('building')
  if (building) {
    chips.push({
      key: 'building',
      label: `Building: ${options.data?.buildings.find((b) => b.id === building)?.name ?? 'selected building'}`,
      onRemove: () => patch({ building: null, page: null }),
    })
  }

  const roomType = params.get('room_type')
  if (roomType) {
    chips.push({
      key: 'room_type',
      label: ROOM_TYPE_LABELS[roomType] ?? 'Other room types',
      onRemove: () => patch({ room_type: null, page: null }),
    })
  }

  if (params.get('without_room') === '1') {
    chips.push({
      key: 'without_room',
      label: 'No room recorded',
      onRemove: () => patch({ without_room: null, page: null }),
    })
  }

  const area = params.get('problem_area')
  if (area) {
    chips.push({
      key: 'problem_area',
      label: `Problem area: ${area
        .split(',')
        .map((value) => value.charAt(0).toUpperCase() + value.slice(1))
        .join(', ')}`,
      onRemove: () => patch({ problem_area: null, page: null }),
    })
  }

  if (params.get('exclude_duplicates') === '1' || params.get('exclude_cancelled') === '1') {
    chips.push({
      key: 'problems',
      label: 'Problems only (no duplicates, nothing cancelled)',
      onRemove: () => patch({ exclude_duplicates: null, exclude_cancelled: null, page: null }),
    })
  }

  if (chips.length === 0) return null

  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm font-semibold text-ink">From a report</p>
      <FilterChips chips={chips} label="Filters from a report" />
    </div>
  )
}
