import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useCreateBuilding, useUpdateBuilding } from '../hooks/mutations'
import { type BuildingForm, buildingSchema } from '../schemas'
import type { BuildingDetail } from '../types'

interface BuildingFormDrawerProps {
  open: boolean
  onClose: () => void
  /** Omit to create; pass a building to edit it. */
  building?: BuildingDetail
  onSaved?: (building: BuildingDetail) => void
}

/**
 * Create or edit a building. One drawer for both, because the fields are
 * identical — the only difference is which mutation runs and what the buttons
 * say. Server-side validation (uniqueness, which the client cannot know) is
 * mapped back onto the fields.
 */
export function BuildingFormDrawer({ open, onClose, building, onSaved }: BuildingFormDrawerProps) {
  const editing = Boolean(building)
  const [formError, setFormError] = useState<string | null>(null)

  const create = useCreateBuilding()
  const update = useUpdateBuilding(building?.id ?? '')

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<BuildingForm>({
    resolver: zodResolver(buildingSchema),
    defaultValues: { name: '', code: '', description: '', address: '' },
  })

  useEffect(() => {
    if (!open) return
    setFormError(null)
    reset({
      name: building?.name ?? '',
      code: building?.code ?? '',
      description: building?.description ?? '',
      address: building?.address ?? '',
    })
  }, [open, building, reset])

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    const payload = {
      name: values.name,
      code: values.code,
      description: values.description || undefined,
      address: values.address || undefined,
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
      title={editing ? 'Edit building' : 'New building'}
      description={
        editing
          ? 'Rename it or correct its details. Floors and rooms are unaffected.'
          : 'Add a building, then give it floors and rooms.'
      }
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button size="sm" loading={isSubmitting} onClick={() => void onSubmit()}>
            {editing ? 'Save building' : 'Create building'}
          </Button>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        {formError && <Alert tone="error">{formError}</Alert>}

        <Field label="Name" error={errors.name?.message} required>
          <Input autoFocus placeholder="Science Hall" {...register('name')} />
        </Field>

        <Field
          label="Code"
          error={errors.code?.message}
          hint="A short identifier used on labels and reports — e.g. SCI-1."
          required
        >
          <Input placeholder="SCI-1" className="font-mono" {...register('code')} />
        </Field>

        <Field label="Address" error={errors.address?.message}>
          <Textarea rows={2} placeholder="14 Fields Road" {...register('address')} />
        </Field>

        <Field label="Description" error={errors.description?.message}>
          <Textarea rows={3} {...register('description')} />
        </Field>
      </form>
    </Drawer>
  )
}
