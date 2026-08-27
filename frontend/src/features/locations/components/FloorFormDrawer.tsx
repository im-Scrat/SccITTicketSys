import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useCreateFloor, useUpdateFloor } from '../hooks/mutations'
import { type FloorForm, floorSchema } from '../schemas'
import type { FloorItem } from '../types'

interface FloorFormDrawerProps {
  open: boolean
  onClose: () => void
  /** The building the floor belongs to (required when creating). */
  buildingId: string
  floor?: FloorItem
}

/**
 * Create or edit a floor. The floor *number* is data, not identity — the room's
 * uuid is — so renumbering a floor is allowed and only has to stay unique within
 * its building, which the server enforces and reports here.
 */
export function FloorFormDrawer({ open, onClose, buildingId, floor }: FloorFormDrawerProps) {
  const editing = Boolean(floor)
  const [formError, setFormError] = useState<string | null>(null)

  const create = useCreateFloor(buildingId)
  const update = useUpdateFloor(floor?.id ?? '')

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FloorForm>({
    resolver: zodResolver(floorSchema),
    defaultValues: { floor_number: 1, name: '', description: '' },
  })

  useEffect(() => {
    if (!open) return
    setFormError(null)
    reset({
      floor_number: floor?.floor_number ?? 1,
      name: floor?.name ?? '',
      description: floor?.description ?? '',
    })
  }, [open, floor, reset])

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    const payload = {
      floor_number: values.floor_number,
      name: values.name,
      description: values.description || undefined,
    }

    try {
      if (editing) await update.mutateAsync(payload)
      else await create.mutateAsync(payload)
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, setError)) setFormError(getErrorMessage(error))
    }
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Edit floor' : 'New floor'}
      description={
        editing
          ? 'Renaming or renumbering a floor keeps its rooms and their history.'
          : 'Floors hold the rooms. Use a negative number for a basement.'
      }
      size="sm"
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button size="sm" loading={isSubmitting} onClick={() => void onSubmit()}>
            {editing ? 'Save floor' : 'Create floor'}
          </Button>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        {formError && <Alert tone="error">{formError}</Alert>}

        <Field
          label="Floor number"
          error={errors.floor_number?.message}
          hint="Ground level is usually 1; basements are negative."
          required
        >
          <Input
            type="number"
            inputMode="numeric"
            className="tnum"
            {...register('floor_number', { valueAsNumber: true })}
          />
        </Field>

        <Field label="Name" error={errors.name?.message} required>
          <Input autoFocus placeholder="Second floor" {...register('name')} />
        </Field>

        <Field label="Description" error={errors.description?.message}>
          <Textarea rows={3} {...register('description')} />
        </Field>
      </form>
    </Drawer>
  )
}
