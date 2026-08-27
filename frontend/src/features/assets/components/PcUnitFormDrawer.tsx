import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useRoomLookup } from '@/hooks/useLocationLookup'
import { useCreatePcUnit, useUpdatePcUnit } from '../hooks/mutations'
import { useAssetCatalog } from '../hooks/queries'
import { type PcUnitForm, pcUnitSchema } from '../schemas'
import type { PcUnitDetail } from '../types'

interface PcUnitFormDrawerProps {
  open: boolean
  onClose: () => void
  pcUnit?: PcUnitDetail
  onSaved?: (id: string) => void
}

/**
 * Create or edit a PC unit.
 *
 * Unlike an asset, a PC's status and room *are* editable here: `PcStatus` has no
 * separate history table in the baselined schema (FR-PC-002), so the audit
 * properties on the update carry the before/after and there is no second write
 * path being bypassed.
 *
 * The specification is edited on the PC's own page rather than in this drawer —
 * it is separately audited, and burying thirteen more fields in a create form
 * would make registering a machine feel like filling in a tax return.
 */
export function PcUnitFormDrawer({ open, onClose, pcUnit, onSaved }: PcUnitFormDrawerProps) {
  const editing = Boolean(pcUnit)
  const [formError, setFormError] = useState<string | null>(null)

  const catalog = useAssetCatalog(open)
  const rooms = useRoomLookup(undefined, open)

  const create = useCreatePcUnit()
  const update = useUpdatePcUnit(pcUnit?.id ?? '')

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PcUnitForm>({
    resolver: zodResolver(pcUnitSchema),
    defaultValues: blankForm(),
  })

  useEffect(() => {
    if (!open) return

    setFormError(null)

    if (pcUnit) {
      reset({
        unit_code: pcUnit.unit_code,
        pc_name: pcUnit.pc_name,
        asset_tag: pcUnit.asset_tag ?? '',
        hostname: pcUnit.hostname ?? '',
        serial_number: pcUnit.serial_number ?? '',
        brand: pcUnit.brand ?? '',
        model: pcUnit.model ?? '',
        room: pcUnit.room?.id ?? '',
        ip_address: pcUnit.network.ip_address ?? '',
        mac_address: pcUnit.network.mac_address ?? '',
        status: pcUnit.status,
        current_condition: pcUnit.condition,
        purchase_date: toDateInput(pcUnit.purchase.date),
        warranty_expiration: toDateInput(pcUnit.warranty.expiration),
        notes: pcUnit.notes ?? '',
      })
    } else {
      reset(blankForm())
    }
  }, [open, pcUnit, reset])

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)

    const payload = {
      unit_code: values.unit_code,
      pc_name: values.pc_name,
      asset_tag: emptyToNull(values.asset_tag),
      hostname: emptyToNull(values.hostname),
      serial_number: emptyToNull(values.serial_number),
      brand: emptyToNull(values.brand),
      model: emptyToNull(values.model),
      room: emptyToNull(values.room),
      ip_address: emptyToNull(values.ip_address),
      mac_address: emptyToNull(values.mac_address),
      status: values.status,
      current_condition: values.current_condition,
      purchase_date: emptyToNull(values.purchase_date),
      warranty_expiration: emptyToNull(values.warranty_expiration),
      notes: emptyToNull(values.notes),
    }

    try {
      const saved = pcUnit ? await update.mutateAsync(payload) : await create.mutateAsync(payload)
      onSaved?.(saved.id)
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(getErrorMessage(error))
      }
    }
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      size="lg"
      title={editing ? 'Edit PC unit' : 'Add PC unit'}
      description={
        editing
          ? 'Hardware specifications are edited on the PC’s own page.'
          : 'Register a machine; you can fill in its specifications afterwards.'
      }
      footer={
        <div className="flex flex-wrap justify-end gap-3">
          <Button variant="secondary" onClick={onClose} type="button">
            Cancel
          </Button>
          <Button type="submit" form="pc-unit-form" disabled={isSubmitting}>
            {editing ? 'Save changes' : 'Add PC unit'}
          </Button>
        </div>
      }
    >
      <form id="pc-unit-form" onSubmit={onSubmit} className="flex flex-col gap-6">
        {formError && <Alert tone="error">{formError}</Alert>}

        <Field label="Unit code" error={errors.unit_code?.message} required>
          <Input {...register('unit_code')} placeholder="LAB7-PC-01" autoFocus />
        </Field>

        <Field label="PC name" error={errors.pc_name?.message} required>
          <Input {...register('pc_name')} placeholder="Lab 7 Station 1" />
        </Field>

        <Field label="Asset tag" error={errors.asset_tag?.message}>
          <Input {...register('asset_tag')} />
        </Field>

        <Field label="Hostname" error={errors.hostname?.message}>
          <Input {...register('hostname')} placeholder="lab7-ws1" />
        </Field>

        <Field label="Serial number" error={errors.serial_number?.message}>
          <Input {...register('serial_number')} />
        </Field>

        <Field label="Brand" error={errors.brand?.message}>
          <Input {...register('brand')} placeholder="Dell" />
        </Field>

        <Field label="Model" error={errors.model?.message}>
          <Input {...register('model')} placeholder="OptiPlex 7090" />
        </Field>

        <Field label="Room" error={errors.room?.message}>
          <Select {...register('room')}>
            <option value="">No room</option>
            {(rooms.data ?? []).map((room) => (
              <option key={room.id} value={room.id}>
                {room.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="IP address" error={errors.ip_address?.message}>
          <Input {...register('ip_address')} placeholder="10.0.4.21" />
        </Field>

        <Field label="MAC address" error={errors.mac_address?.message}>
          <Input {...register('mac_address')} placeholder="00:1B:44:11:3A:B7" />
        </Field>

        <Field label="Status" error={errors.status?.message}>
          <Select {...register('status')}>
            {(catalog.data?.pc_statuses ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Condition" error={errors.current_condition?.message}>
          <Select {...register('current_condition')}>
            {(catalog.data?.conditions ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Purchase date" error={errors.purchase_date?.message}>
          <Input type="date" {...register('purchase_date')} />
        </Field>

        <Field label="Warranty expires" error={errors.warranty_expiration?.message}>
          <Input type="date" {...register('warranty_expiration')} />
        </Field>

        <Field label="Notes" error={errors.notes?.message}>
          <Textarea rows={4} {...register('notes')} />
        </Field>
      </form>
    </Drawer>
  )
}

function blankForm(): PcUnitForm {
  return {
    unit_code: '',
    pc_name: '',
    asset_tag: '',
    hostname: '',
    serial_number: '',
    brand: '',
    model: '',
    room: '',
    ip_address: '',
    mac_address: '',
    status: 'available',
    current_condition: 'working',
    purchase_date: '',
    warranty_expiration: '',
    notes: '',
  }
}

function emptyToNull(value: string | undefined): string | null {
  const trimmed = value?.trim() ?? ''
  return trimmed === '' ? null : trimmed
}

function toDateInput(value: string | null | undefined): string {
  if (!value) return ''
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? '' : parsed.toISOString().slice(0, 10)
}
