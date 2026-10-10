import { type FormEvent, useState } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import type { LayoutCreateRequest } from '../types'

/** Room sizes an editor can pick without thinking in pixels. Layout units are pixels of the plan. */
const PRESETS = [
  { key: 'office', label: 'Small office (800 × 500)', width: 800, height: 500 },
  { key: 'lab', label: 'Standard lab (1200 × 800)', width: 1200, height: 800 },
  { key: 'large', label: 'Large lab (1800 × 1000)', width: 1800, height: 1000 },
  { key: 'custom', label: 'Custom size', width: null, height: null },
] as const

type PresetKey = (typeof PRESETS)[number]['key']

const MAX_SIDE = 100000
const MAX_GRID = 1000

interface CreateLayoutFormProps {
  onSubmit: (request: LayoutCreateRequest) => Promise<unknown>
  submitLabel?: string
  /** Render without the submit button — a dialog's confirm button submits by form id. */
  id?: string
  hideSubmit?: boolean
}

/**
 * The size of a new layout version (FR-FP-001). Validation mirrors the
 * server's `StoreRoomLayoutRequest` so a refusal is the exception, not the
 * normal path; the server still validates, and its field errors are shown.
 * Grid size is optional — left blank, the server applies the configured
 * default (`floor_plan.default_grid_size`).
 */
export function CreateLayoutForm({
  onSubmit,
  submitLabel = 'Create floor plan',
  id,
  hideSubmit = false,
}: CreateLayoutFormProps) {
  const [preset, setPreset] = useState<PresetKey>('lab')
  const [width, setWidth] = useState('1200')
  const [height, setHeight] = useState('800')
  const [grid, setGrid] = useState('')
  const [errors, setErrors] = useState<Partial<Record<'width' | 'height' | 'grid', string>>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const choosePreset = (key: PresetKey) => {
    setPreset(key)
    const found = PRESETS.find((p) => p.key === key)
    if (found && found.width !== null && found.height !== null) {
      setWidth(String(found.width))
      setHeight(String(found.height))
    }
  }

  const validate = () => {
    const next: typeof errors = {}
    const w = Number(width)
    const h = Number(height)
    if (!Number.isInteger(w) || w < 1 || w > MAX_SIDE)
      next.width = `Enter a whole number from 1 to ${MAX_SIDE}.`
    if (!Number.isInteger(h) || h < 1 || h > MAX_SIDE)
      next.height = `Enter a whole number from 1 to ${MAX_SIDE}.`
    if (grid.trim() !== '') {
      const g = Number(grid)
      if (!Number.isInteger(g) || g < 1 || g > MAX_GRID)
        next.grid = `Enter a whole number from 1 to ${MAX_GRID}, or leave blank.`
    }
    setErrors(next)
    return Object.keys(next).length === 0
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setFormError(null)
    if (!validate()) return

    setBusy(true)
    try {
      await onSubmit({
        width: Number(width),
        height: Number(height),
        grid_size: grid.trim() === '' ? undefined : Number(grid),
      })
    } catch (error) {
      setFormError(serverMessage(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form id={id} onSubmit={(e) => void submit(e)} noValidate className="flex flex-col gap-4">
      {formError && <Alert tone="error">{formError}</Alert>}

      <Field label="Room size">
        <Select value={preset} onChange={(e) => choosePreset(e.target.value as PresetKey)}>
          {PRESETS.map((p) => (
            <option key={p.key} value={p.key}>
              {p.label}
            </option>
          ))}
        </Select>
      </Field>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Field label="Width" error={errors.width} required>
          <Input
            inputMode="numeric"
            value={width}
            onChange={(e) => {
              setWidth(e.target.value)
              setPreset('custom')
            }}
          />
        </Field>
        <Field label="Height" error={errors.height} required>
          <Input
            inputMode="numeric"
            value={height}
            onChange={(e) => {
              setHeight(e.target.value)
              setPreset('custom')
            }}
          />
        </Field>
        <Field label="Grid cell" error={errors.grid} hint="Blank uses the system default.">
          <Input
            inputMode="numeric"
            value={grid}
            onChange={(e) => setGrid(e.target.value)}
            placeholder="20"
          />
        </Field>
      </div>

      {!hideSubmit && (
        <div>
          <Button type="submit" variant="primary" loading={busy}>
            {submitLabel}
          </Button>
        </div>
      )}
    </form>
  )
}

/** The server's own message when it has one; a plain sentence otherwise. */
function serverMessage(error: unknown): string {
  const data = (
    error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  )?.response?.data
  const field = data?.errors ? Object.values(data.errors).flat()[0] : undefined
  return field ?? data?.message ?? 'The floor plan could not be created. Please try again.'
}
