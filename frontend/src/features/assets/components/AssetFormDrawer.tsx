import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useRoomLookup } from '@/hooks/useLocationLookup'
import { useCreateAsset, useUpdateAsset } from '../hooks/mutations'
import { useAssetCatalog } from '../hooks/queries'
import { type AssetForm, assetSchema } from '../schemas'
import type { AssetDetail } from '../types'

interface AssetFormDrawerProps {
  open: boolean
  onClose: () => void
  asset?: AssetDetail
  onSaved?: (id: string) => void
}

/**
 * Create or edit an asset.
 *
 * The catalog cascade is category → model: an administrator thinks "a printer,
 * the LaserJet 4200", not "hardware_model 17". Choosing a category narrows the
 * model list rather than filtering it silently, so the relationship between the
 * two fields is visible.
 *
 * **Status and room are absent when editing.** Both have their own audited write
 * paths (status writes `asset_status_history`, a move writes `asset_transfers`),
 * and the API refuses them here — so offering them in this form would promise
 * something the server will not do. On *create* the opening status is offered,
 * because that is the asset's first state rather than a transition.
 */
export function AssetFormDrawer({ open, onClose, asset, onSaved }: AssetFormDrawerProps) {
  const editing = Boolean(asset)
  const [formError, setFormError] = useState<string | null>(null)
  const [category, setCategory] = useState<string>('')

  const catalog = useAssetCatalog(open)
  const rooms = useRoomLookup(undefined, open)

  const create = useCreateAsset()
  const update = useUpdateAsset(asset?.id ?? '')

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AssetForm>({
    resolver: zodResolver(assetSchema),
    defaultValues: blankForm(),
  })

  useEffect(() => {
    if (!open) return

    setFormError(null)

    if (asset) {
      setCategory(asset.category ?? '')
      reset({
        asset_tag: asset.asset_tag,
        name: asset.name ?? '',
        hardware_model: asset.model?.id ?? 0,
        supplier: asset.supplier?.name ?? '',
        room: asset.room?.id ?? '',
        technician: asset.technician?.id ?? '',
        serial_number: asset.serial_number ?? '',
        barcode: asset.barcode ?? '',
        status: asset.status,
        condition: asset.condition,
        purchase_price: asset.purchase.price ? Number(asset.purchase.price) : null,
        purchase_date: toDateInput(asset.purchase.date),
        warranty_expiration: toDateInput(asset.warranty.expiration),
        notes: asset.notes ?? '',
      })
    } else {
      setCategory('')
      reset(blankForm())
    }
  }, [open, asset, reset])

  const models = useMemo(() => {
    const all = catalog.data?.models ?? []
    return category ? all.filter((model) => model.category === category) : all
  }, [catalog.data, category])

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)

    const payload = {
      asset_tag: values.asset_tag,
      name: emptyToNull(values.name),
      hardware_model: values.hardware_model,
      supplier: emptyToNull(values.supplier),
      serial_number: emptyToNull(values.serial_number),
      barcode: emptyToNull(values.barcode),
      condition: values.condition,
      purchase_price: values.purchase_price ?? null,
      purchase_date: emptyToNull(values.purchase_date),
      warranty_expiration: emptyToNull(values.warranty_expiration),
      notes: emptyToNull(values.notes),
    }

    try {
      if (asset) {
        const updated = await update.mutateAsync(payload)
        onSaved?.(updated.id)
      } else {
        const created = await create.mutateAsync({
          ...payload,
          // Only meaningful on create: the asset's opening state and placement.
          status: values.status,
          room: emptyToNull(values.room),
          technician: emptyToNull(values.technician),
        })
        onSaved?.(created.id)
      }
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
      title={editing ? 'Edit asset' : 'Add asset'}
      description={
        editing
          ? 'Status and location are changed from the asset page, where each change is recorded.'
          : 'Register a new piece of equipment in the estate.'
      }
      footer={
        <div className="flex flex-wrap justify-end gap-3">
          <Button variant="secondary" onClick={onClose} type="button">
            Cancel
          </Button>
          <Button type="submit" form="asset-form" disabled={isSubmitting}>
            {editing ? 'Save changes' : 'Add asset'}
          </Button>
        </div>
      }
    >
      <form id="asset-form" onSubmit={onSubmit} className="flex flex-col gap-6">
        {formError && <Alert tone="error">{formError}</Alert>}

        <Field label="Asset tag" error={errors.asset_tag?.message} required>
          <Input {...register('asset_tag')} placeholder="LAB-PRN-01" autoFocus />
        </Field>

        <Field
          label="Asset name"
          error={errors.name?.message}
          hint="Leave blank to use the catalog model name."
        >
          <Input {...register('name')} placeholder="Lab 3 Front Printer" />
        </Field>

        <Field label="Category" hint="Narrows the model list below.">
          <Select value={category} onChange={(event) => setCategory(event.target.value)}>
            <option value="">All categories</option>
            {(catalog.data?.categories ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Catalog model" error={errors.hardware_model?.message} required>
          <Select {...register('hardware_model', { valueAsNumber: true })}>
            <option value={0}>Choose a model…</option>
            {models.map((model) => (
              <option key={model.value} value={model.value}>
                {model.manufacturer ? `${model.manufacturer} — ` : ''}
                {model.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Serial number" error={errors.serial_number?.message}>
          <Input {...register('serial_number')} />
        </Field>

        <Field label="Barcode" error={errors.barcode?.message}>
          <Input {...register('barcode')} />
        </Field>

        <Field label="Condition" error={errors.condition?.message}>
          <Select {...register('condition')}>
            {(catalog.data?.conditions ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        {!editing && (
          <>
            <Field label="Opening status" error={errors.status?.message}>
              <Select {...register('status')}>
                {(catalog.data?.statuses ?? []).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Room" error={errors.room?.message}>
              <Select {...register('room')}>
                <option value="">No room yet</option>
                {(rooms.data ?? []).map((room) => (
                  <option key={room.id} value={room.id}>
                    {room.label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Custodian" error={errors.technician?.message}>
              <Select {...register('technician')}>
                <option value="">Unassigned</option>
                {(catalog.data?.technicians ?? []).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label} ({option.role})
                  </option>
                ))}
              </Select>
            </Field>
          </>
        )}

        <Field label="Supplier" error={errors.supplier?.message}>
          <Select {...register('supplier')}>
            <option value="">No supplier</option>
            {(catalog.data?.suppliers ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Purchase price" error={errors.purchase_price?.message}>
          <Input
            type="number"
            step="0.01"
            min="0"
            {...register('purchase_price', {
              setValueAs: (value) => (value === '' ? null : Number(value)),
            })}
          />
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

function blankForm(): AssetForm {
  return {
    asset_tag: '',
    name: '',
    hardware_model: 0,
    supplier: '',
    room: '',
    technician: '',
    serial_number: '',
    barcode: '',
    status: 'new',
    condition: 'working',
    purchase_price: null,
    purchase_date: '',
    warranty_expiration: '',
    notes: '',
  }
}

/** A blank field means "not set", which the API expects as null, not "". */
function emptyToNull(value: string | undefined): string | null {
  const trimmed = value?.trim() ?? ''
  return trimmed === '' ? null : trimmed
}

/** ISO timestamp → the `yyyy-mm-dd` an `<input type="date">` needs. */
function toDateInput(value: string | null | undefined): string {
  if (!value) return ''
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? '' : parsed.toISOString().slice(0, 10)
}
