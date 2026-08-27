import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useBuildingLookup, useFloorLookup } from '@/hooks/useLocationLookup'
import { useCreateRoom, useUpdateRoom } from '../hooks/mutations'
import { type RoomForm, roomSchema, roomTypeValues } from '../schemas'
import type { RoomDetail, RoomType } from '../types'

const ROOM_TYPE_LABELS: Record<RoomType, string> = {
  laboratory: 'Laboratory',
  office: 'Office',
  storage: 'Storage',
  server_room: 'Server room',
  faculty_room: 'Faculty room',
  library: 'Library',
  other: 'Other',
}

interface RoomFormDrawerProps {
  open: boolean
  onClose: () => void
  room?: RoomDetail
  /** Pre-selected floor when adding a room from a building/floor context. */
  defaultFloorId?: string
  defaultBuildingId?: string
  onSaved?: (room: RoomDetail) => void
}

/**
 * Create or edit a room, including moving it to another floor.
 *
 * The floor is chosen through a building → floor cascade: administrators think
 * "Science Hall, second floor", not "floor uuid". Only selectable (active,
 * non-archived) parents are offered, so a room can never be created somewhere
 * that is out of service.
 */
export function RoomFormDrawer({
  open,
  onClose,
  room,
  defaultFloorId,
  defaultBuildingId,
  onSaved,
}: RoomFormDrawerProps) {
  const editing = Boolean(room)
  const [formError, setFormError] = useState<string | null>(null)
  const [buildingId, setBuildingId] = useState<string>('')

  const buildings = useBuildingLookup(open)
  const floors = useFloorLookup(buildingId || undefined, open)

  const create = useCreateRoom()
  const update = useUpdateRoom(room?.id ?? '')

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RoomForm>({
    resolver: zodResolver(roomSchema),
    defaultValues: {
      floor: '',
      name: '',
      code: '',
      room_number: '',
      room_type: 'laboratory',
      capacity: null,
      description: '',
    },
  })

  useEffect(() => {
    if (!open) return
    setFormError(null)
    setBuildingId(room?.building?.id ?? defaultBuildingId ?? '')
    reset({
      floor: room?.floor?.id ?? defaultFloorId ?? '',
      name: room?.name ?? '',
      code: room?.code ?? '',
      room_number: room?.room_number ?? '',
      room_type: room?.room_type ?? 'laboratory',
      capacity: room?.capacity ?? null,
      description: room?.description ?? '',
    })
  }, [open, room, defaultFloorId, defaultBuildingId, reset])

  const selectedFloor = watch('floor')

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    const payload = {
      floor: values.floor,
      name: values.name,
      code: values.code,
      room_number: values.room_number || undefined,
      room_type: values.room_type,
      capacity: values.capacity,
      description: values.description || undefined,
    }

    try {
      const saved = editing ? await update.mutateAsync(payload) : await create.mutateAsync(payload)
      onSaved?.(saved)
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, setError)) setFormError(getErrorMessage(error))
    }
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Edit room' : 'New room'}
      description={
        editing
          ? 'Change its details, or move it to another floor — its history follows it.'
          : 'Rooms are where PCs, assets and tickets are located.'
      }
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button size="sm" loading={isSubmitting} onClick={() => void onSubmit()}>
            {editing ? 'Save room' : 'Create room'}
          </Button>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        {formError && <Alert tone="error">{formError}</Alert>}

        <Field label="Building" hint="Only buildings in service are listed.">
          <Select
            value={buildingId}
            onChange={(event) => {
              setBuildingId(event.target.value)
              setValue('floor', '', { shouldValidate: false })
            }}
          >
            <option value="">Choose a building…</option>
            {(buildings.data ?? []).map((building) => (
              <option key={building.id} value={building.id}>
                {building.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Floor" error={errors.floor?.message} required>
          <Select disabled={!buildingId} {...register('floor')} value={selectedFloor}>
            <option value="">{buildingId ? 'Choose a floor…' : 'Choose a building first'}</option>
            {(floors.data ?? []).map((floor) => (
              <option key={floor.id} value={floor.id}>
                {floor.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Name" error={errors.name?.message} required>
          <Input placeholder="Computer Lab 1" {...register('name')} />
        </Field>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Code" error={errors.code?.message} required>
            <Input placeholder="LAB-1" className="font-mono" {...register('code')} />
          </Field>
          <Field label="Room number" error={errors.room_number?.message}>
            <Input placeholder="204" className="tnum" {...register('room_number')} />
          </Field>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Type" error={errors.room_type?.message} required>
            <Select {...register('room_type')}>
              {roomTypeValues.map((value) => (
                <option key={value} value={value}>
                  {ROOM_TYPE_LABELS[value]}
                </option>
              ))}
            </Select>
          </Field>
          <Field
            label="Capacity"
            error={errors.capacity?.message}
            hint="Seats. Leave blank if n/a."
          >
            <Input
              type="number"
              inputMode="numeric"
              min={0}
              className="tnum"
              {...register('capacity', {
                setValueAs: (value: string) => (value === '' ? null : Number(value)),
              })}
            />
          </Field>
        </div>

        <Field label="Description" error={errors.description?.message}>
          <Textarea rows={3} {...register('description')} />
        </Field>
      </form>
    </Drawer>
  )
}
