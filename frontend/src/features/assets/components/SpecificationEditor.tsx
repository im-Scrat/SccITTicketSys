import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Field, Input } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useUpdateSpecification } from '../hooks/mutations'
import { type SpecificationForm, specificationSchema } from '../schemas'
import type { PcSpecification } from '../types'

/**
 * The PC specification editor (SRS FR-PC-003).
 *
 * Every field is free text on purpose: specifications are transcribed from
 * whatever the hardware actually reports ("16 GB DDR4-3200", "512 GB NVMe SSD"),
 * and forcing them into structured units would make honest data impossible to
 * enter. Placeholders show the expected shape without constraining it.
 *
 * Grouped into Processing / Storage & graphics / Peripherals / System so a
 * thirteen-field form reads as four short ones.
 */
const GROUPS: Array<{
  title: string
  fields: Array<{ name: keyof SpecificationForm; label: string; placeholder: string }>
}> = [
  {
    title: 'Processing',
    fields: [
      { name: 'cpu', label: 'CPU', placeholder: 'Intel Core i5-12400' },
      { name: 'motherboard', label: 'Motherboard', placeholder: 'ASUS PRIME B660M-A' },
      { name: 'ram', label: 'RAM', placeholder: '16 GB DDR4-3200' },
      { name: 'power_supply', label: 'Power supply', placeholder: '500 W 80+ Bronze' },
    ],
  },
  {
    title: 'Storage & graphics',
    fields: [
      { name: 'storage_primary', label: 'Primary storage', placeholder: '512 GB NVMe SSD' },
      { name: 'storage_secondary', label: 'Secondary storage', placeholder: '1 TB HDD' },
      { name: 'gpu', label: 'Graphics', placeholder: 'Intel UHD 730' },
    ],
  },
  {
    title: 'Peripherals',
    fields: [
      { name: 'monitor', label: 'Monitor', placeholder: 'Dell P2422H 24"' },
      { name: 'keyboard', label: 'Keyboard', placeholder: 'Dell KB216' },
      { name: 'mouse', label: 'Mouse', placeholder: 'Dell MS116' },
    ],
  },
  {
    title: 'System',
    fields: [
      { name: 'operating_system', label: 'Operating system', placeholder: 'Windows 11 Pro' },
      { name: 'bios_version', label: 'BIOS version', placeholder: '2.14.0' },
      { name: 'network_adapter', label: 'Network adapter', placeholder: 'Realtek RTL8111' },
    ],
  },
]

export function SpecificationEditor({
  pcUnitId,
  specification,
  canEdit,
}: {
  pcUnitId: string
  specification: PcSpecification | null
  canEdit: boolean
}) {
  const [editing, setEditing] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const update = useUpdateSpecification(pcUnitId)

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<SpecificationForm>({
    resolver: zodResolver(specificationSchema),
    defaultValues: toForm(specification),
  })

  useEffect(() => {
    reset(toForm(specification))
  }, [specification, reset])

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await update.mutateAsync(values)
      setEditing(false)
      setSaved(true)
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(getErrorMessage(error))
      }
    }
  })

  if (!editing) {
    return (
      <div className="flex flex-col gap-8">
        {saved && (
          <Alert tone="success" title="Specification updated">
            The change is recorded in this PC’s history.
          </Alert>
        )}

        {GROUPS.map((group) => (
          <section key={group.title}>
            <h3 className="text-base font-bold text-ink-strong">{group.title}</h3>
            <dl className="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">
              {group.fields.map((field) => (
                <div key={field.name}>
                  <dt className="text-xs font-semibold uppercase tracking-wide text-muted">
                    {field.label}
                  </dt>
                  <dd className="mt-1 text-base text-ink">
                    {specification?.[field.name] ?? (
                      <span className="text-muted">Not recorded</span>
                    )}
                  </dd>
                </div>
              ))}
            </dl>
          </section>
        ))}

        {canEdit && (
          <div>
            <Button
              onClick={() => {
                setSaved(false)
                setEditing(true)
              }}
            >
              Edit specification
            </Button>
          </div>
        )}
      </div>
    )
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-8">
      {formError && <Alert tone="error">{formError}</Alert>}

      {GROUPS.map((group) => (
        <section key={group.title}>
          <h3 className="text-base font-bold text-ink-strong">{group.title}</h3>
          <div className="mt-4 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
            {group.fields.map((field) => (
              <Field key={field.name} label={field.label} error={errors[field.name]?.message}>
                <Input {...register(field.name)} placeholder={field.placeholder} />
              </Field>
            ))}
          </div>
        </section>
      ))}

      <div className="flex flex-wrap gap-3">
        <Button type="submit" disabled={isSubmitting}>
          Save specification
        </Button>
        <Button
          type="button"
          variant="secondary"
          onClick={() => {
            reset(toForm(specification))
            setEditing(false)
          }}
        >
          Cancel
        </Button>
      </div>
    </form>
  )
}

/** Null columns become empty strings, which is what a text input expects. */
function toForm(specification: PcSpecification | null): SpecificationForm {
  const blank = Object.fromEntries(
    GROUPS.flatMap((group) => group.fields).map((field) => [field.name, '']),
  ) as SpecificationForm

  if (!specification) return blank

  for (const key of Object.keys(blank) as Array<keyof SpecificationForm>) {
    blank[key] = specification[key] ?? ''
  }

  return blank
}
