import { useState } from 'react'
import { Combobox } from '@/components/ui'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useRoomOptions } from '../hooks/queries'
import type { RoomOption } from '../types'

/**
 * Any room in the estate, searched on the server (Phase 2.10 R6). Rooms not
 * in use are listed — marked, and assignable only with "assign anyway";
 * archived rooms never appear.
 */
export function RoomCombobox({
  label,
  value,
  selectedLabel,
  onChange,
  hint,
  error,
}: {
  label: string
  value: string | null
  selectedLabel?: string
  onChange: (room: RoomOption | null) => void
  hint?: string
  error?: string
}) {
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search, 300)
  const rooms = useRoomOptions(debounced)
  const list = rooms.data ?? []

  return (
    <Combobox
      label={label}
      value={value}
      selectedLabel={selectedLabel}
      options={list.map((room) => ({
        value: room.id,
        label: room.label,
        description: room.selectable ? undefined : 'Not in use',
      }))}
      onSearch={setSearch}
      loading={rooms.isFetching}
      onChange={(id) =>
        onChange(id === null ? null : (list.find((room) => room.id === id) ?? null))
      }
      placeholder="Search rooms by name or code"
      hint={hint}
      error={error}
      emptyText="No room matches"
    />
  )
}
