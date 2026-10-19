import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useRoomLookup } from '@/hooks/useLocationLookup'
import { useCreateAsset, useUpdateAsset } from '../hooks/mutations'
import { useAssetCatalog } from '../hooks/queries'
import { type AssetForm, assetSchema, NO_MODEL_VALUE, OTHER_MODEL_VALUE } from '../schemas'
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
 * **Everything is optional** (owner request, 2026-10-19), and nothing is
 * marked "(optional)" — there is nothing else. There is no asset tag: the
 * server generates one internally and it is shown nowhere. A model that is not
 * in the catalog is chosen as "Other", which opens a text box right under the
 * select; the server files it in the catalog so the next asset can pick it.
 * Every detail can be changed later from the asset page (administrators only).
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
    watch,
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
        name: asset.name ?? '',
        hardware_model: asset.model?.id ?? NO_MODEL_VALUE,
        hardware_model_other: '',
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

  const isOtherModel = watch('hardware_model') === OTHER_MODEL_VALUE

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)

    const payload = {
      name: emptyToNull(values.name),
      // One or the other, never both: a chosen model, or a described one.
      hardware_model: values.hardware_model > 0 ? values.hardware_model : null,
      hardware_model_other:
        values.hardware_model === OTHER_MODEL_VALUE
          ? emptyToNull(values.hardware_model_other)
          : null,
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

        <Field
          label="Asset name"
          error={errors.name?.message}
          hint="Leave blank to use the catalog model name."
        >
          <Input {...register('name')} placeholder="Lab 3 Front Printer" autoFocus />
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

        <Field label="Catalog model" error={errors.hardware_model?.message}>
          <Select {...register('hardware_model', { valueAsNumber: true })}>
            <option value={NO_MODEL_VALUE}>Not specified</option>
            {models.map((model) => (
              <option key={model.value} value={model.value}>
                {model.manufacturer ? `${model.manufacturer} — ` : ''}
                {model.label}
              </option>
            ))}
            <option value={OTHER_MODEL_VALUE}>Other — describe it</option>
          </Select>
        </Field>

        {isOtherModel && (
          <Field
            label="Describe the catalog model"
            error={errors.hardware_model_other?.message}
            hint="Brand and model, e.g. Epson EB-X51. It is added to the catalog for next time."
          >
            <Input {...register('hardware_model_other')} autoFocus />
          </Field>
        )}

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
    name: '',
    hardware_model: NO_MODEL_VALUE,
    hardware_model_other: '',
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
