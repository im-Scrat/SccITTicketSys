import { useState } from 'react'
import { LayoutTemplate } from 'lucide-react'
import { isAxiosError } from 'axios'
import { Alert, Button, Field, Input } from '@/components/ui'
import { cn } from '@/lib/cn'

const PRESETS = [
  { key: 'small', label: 'Small room', hint: 'About 20 workstations', width: 800, height: 500 },
  { key: 'medium', label: 'Computer lab', hint: 'About 40 workstations', width: 1200, height: 800 },
  { key: 'large', label: 'Large lab', hint: '60 or more', width: 1800, height: 1000 },
] as const

/**
 * A room with no Floorplan yet (Phase 2.11). An administrator picks a size and
 * the layout is created *and switched on* in one step (`activate: true`), then
 * the editor opens straight into edit mode. A viewer who may not edit is told
 * so plainly rather than shown a form they cannot submit.
 */
export function SetupLayout({
  roomName,
  machineCount,
  canEdit,
  onCreate,
  creating,
  error,
}: {
  roomName: string
  machineCount: number
  canEdit: boolean
  onCreate: (size: { width: number; height: number; grid_size: number }) => void
  creating: boolean
  error: unknown
}) {
  const [choice, setChoice] = useState<string>('medium')
  const [width, setWidth] = useState('1200')
  const [height, setHeight] = useState('800')
  const [fieldError, setFieldError] = useState<string | null>(null)

  const submit = () => {
    const preset = PRESETS.find((p) => p.key === choice)
    const size = preset
      ? { width: preset.width, height: preset.height }
      : { width: Number(width), height: Number(height) }
    if (![size.width, size.height].every((v) => Number.isInteger(v) && v >= 100 && v <= 20000)) {
      setFieldError('Width and depth must be whole numbers between 100 and 20,000.')
      return
    }
    setFieldError(null)
    onCreate({ ...size, grid_size: 20 })
  }

  const serverError =
    error && isAxiosError(error) && error.response?.status === 403
      ? 'You do not have permission to set up this Floorplan.'
      : error
        ? 'The Floorplan could not be created. Try again.'
        : null

  return (
    <div className="mx-auto flex max-w-2xl flex-col items-center gap-6 px-4 py-12 text-center">
      <span
        className="grid size-14 place-items-center rounded-lg bg-surface-sunken text-muted"
        aria-hidden="true"
      >
        <LayoutTemplate className="size-7" />
      </span>
      <div>
        <h2 className="text-xl font-bold text-ink-strong text-balance">
          {roomName} has no Floorplan yet
        </h2>
        <p className="mt-2 text-base text-muted text-pretty">
          {canEdit
            ? `Choose a size to start. ${machineCount === 0 ? 'No PCs are registered to this room yet — you can still draw it.' : `${machineCount} PC${machineCount === 1 ? '' : 's'} registered to this room will be ready to place.`}`
            : 'An administrator who can edit Floorplans has not set this room up yet.'}
        </p>
      </div>

      {canEdit && (
        <form
          className="flex w-full flex-col gap-5 text-left"
          onSubmit={(e) => {
            e.preventDefault()
            submit()
          }}
        >
          {(fieldError || serverError) && <Alert tone="error">{fieldError ?? serverError}</Alert>}
          <fieldset className="grid gap-3 sm:grid-cols-2">
            <legend className="mb-2 text-sm font-semibold text-ink">Room size</legend>
            {[
              ...PRESETS,
              { key: 'custom', label: 'Custom size', hint: 'Enter your own', width: 0, height: 0 },
            ].map((preset) => (
              <label
                key={preset.key}
                className={cn(
                  'flex cursor-pointer items-start gap-3 rounded-md border p-4 transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-focus',
                  choice === preset.key
                    ? 'border-primary bg-primary-subtle'
                    : 'border-border hover:bg-surface-sunken',
                )}
              >
                <input
                  type="radio"
                  name="layout-size"
                  value={preset.key}
                  checked={choice === preset.key}
                  onChange={() => setChoice(preset.key)}
                  className="mt-1 size-5 accent-primary"
                />
                <span>
                  <span className="block text-sm font-semibold text-ink-strong">
                    {preset.label}
                  </span>
                  <span className="block text-xs text-muted">
                    {preset.width
                      ? `${preset.width} × ${preset.height} · ${preset.hint}`
                      : preset.hint}
                  </span>
                </span>
              </label>
            ))}
          </fieldset>
          {choice === 'custom' && (
            <div className="grid grid-cols-2 gap-4">
              <Field label="Width">
                <Input
                  type="number"
                  inputMode="numeric"
                  value={width}
                  onChange={(e) => setWidth(e.target.value)}
                />
              </Field>
              <Field label="Depth">
                <Input
                  type="number"
                  inputMode="numeric"
                  value={height}
                  onChange={(e) => setHeight(e.target.value)}
                />
              </Field>
            </div>
          )}
          <Button type="submit" loading={creating} className="self-center">
            Set up Floorplan
          </Button>
        </form>
      )}
    </div>
  )
}
