import { useId } from 'react'
import { cn } from '@/lib/cn'
import type { ExportFormat } from '../../types'

const FORMATS: Array<{ value: ExportFormat; label: string; purpose: string }> = [
  {
    value: 'pdf',
    label: 'PDF',
    purpose: 'To read and print. Charts with their figures; long record lists go to Excel.',
  },
  { value: 'docx', label: 'Word', purpose: 'To edit. The same content as the PDF, in Word.' },
  { value: 'xlsx', label: 'Excel', purpose: 'Every record, with native charts and filters.' },
  { value: 'csv', label: 'CSV', purpose: 'One table of records, for another program.' },
]

const OPTION =
  'flex min-h-15 cursor-pointer items-start gap-3 border-2 border-transparent bg-surface px-4 py-3 transition-colors duration-150 [transition-timing-function:var(--ease-standard)] hover:bg-surface-sunken has-[:checked]:border-primary'

interface FormatChoiceProps {
  value: ExportFormat
  onChange: (value: ExportFormat) => void
  /** The formats on offer (email offers no CSV). */
  formats?: ExportFormat[]
  legend?: string
}

/** The document format, each with a one-line purpose so the choice explains itself. */
export function FormatChoice({
  value,
  onChange,
  formats = ['pdf', 'docx', 'xlsx', 'csv'],
  legend = 'Format',
}: FormatChoiceProps) {
  const name = useId()
  const shown = FORMATS.filter((format) => formats.includes(format.value))

  return (
    <fieldset className="flex min-w-0 flex-col">
      <legend className="mb-3 text-sm font-semibold text-ink">{legend}</legend>
      <div className="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-2">
        {shown.map((format) => (
          <label key={format.value} className={cn(OPTION)}>
            <input
              type="radio"
              name={name}
              value={format.value}
              checked={value === format.value}
              onChange={() => onChange(format.value)}
              aria-labelledby={`${name}-${format.value}-label`}
              aria-describedby={`${name}-${format.value}`}
              className="mt-1 size-5 shrink-0 accent-primary"
            />
            <span className="flex min-w-0 flex-col gap-0.5">
              <span id={`${name}-${format.value}-label`} className="text-sm font-semibold text-ink">
                {format.label}
              </span>
              <span id={`${name}-${format.value}`} className="text-xs text-muted">
                {format.purpose}
              </span>
            </span>
          </label>
        ))}
      </div>
    </fieldset>
  )
}
