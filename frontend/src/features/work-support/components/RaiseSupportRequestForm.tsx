import { HandHelping, Paperclip, Plus, X } from 'lucide-react'
import { type ChangeEvent, type FormEvent, useRef, useState } from 'react'
import { Alert, Button, Field, Input, Surface, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useRaiseSupportRequest } from '../hooks/mutations'
import type { SupportRequest } from '../types'

interface DraftItem {
  description: string
  quantity: string
  remarks: string
}

const EMPTY_ITEM: DraftItem = { description: '', quantity: '1', remarks: '' }

/**
 * Asking for what the job needs (SRS FR-WSR-001/002/003).
 *
 * ── Collapsed until it is wanted ───────────────────────────────────────────
 *
 * This sits on the scan panel *below* the proof-of-work form, behind a single
 * button, because the two are different moments: proof is what a technician
 * submits when the job is done, and a support request is what they submit when
 * it cannot be. Showing both forms open at once would present a screen of empty
 * fields to someone who came to do one thing.
 *
 * ── Free text first, catalogue second ──────────────────────────────────────
 *
 * The item rows take a plain description rather than a catalogue picker. A
 * technician standing at a machine needs to be able to ask for a part the
 * catalogue has never carried, and the API accepts either — a picker that made
 * the catalogue mandatory would block exactly the requests that matter most.
 */
export function RaiseSupportRequestForm({
  code,
  maintenanceId,
  onSubmitted,
}: {
  code: string
  /** The job this need arose from, when the panel knows which one. */
  maintenanceId?: string
  onSubmitted: (request: SupportRequest) => void
}) {
  const raise = useRaiseSupportRequest(code)
  const fileInput = useRef<HTMLInputElement>(null)

  const [open, setOpen] = useState(false)
  const [explanation, setExplanation] = useState('')
  const [items, setItems] = useState<DraftItem[]>([{ ...EMPTY_ITEM }])
  const [files, setFiles] = useState<File[]>([])
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  function patchItem(index: number, patch: Partial<DraftItem>) {
    setItems((current) =>
      current.map((item, position) => (position === index ? { ...item, ...patch } : item)),
    )
  }

  function addFiles(event: ChangeEvent<HTMLInputElement>) {
    const picked = Array.from(event.target.files ?? [])
    event.target.value = ''
    if (picked.length > 0) setFiles((current) => [...current, ...picked].slice(0, 5))
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})

    try {
      const created = await raise.mutateAsync({
        maintenanceId,
        explanation,
        items: items
          .filter((item) => item.description.trim() !== '')
          .map((item) => ({
            description: item.description.trim(),
            quantity: Number(item.quantity) || 1,
            remarks: item.remarks.trim() || undefined,
          })),
        evidence: files,
      })

      // Reset before handing over, so reopening the form starts clean rather
      // than showing a request that has already been sent.
      setExplanation('')
      setItems([{ ...EMPTY_ITEM }])
      setFiles([])
      setOpen(false)
      onSubmitted(created)
    } catch (caught) {
      setError(getErrorMessage(caught))
      setFieldErrors(collectFieldErrors(caught))
    }
  }

  if (!open) {
    return (
      <Surface className="p-6 sm:p-8">
        <div className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold text-ink">Can&rsquo;t finish this job?</h2>
          <p className="max-w-[65ch] text-sm leading-relaxed text-pretty text-muted">
            If the job needs a part, a material or a decision before it can be completed, ask an
            administrator. Your request keeps the machine, the job and your explanation together.
          </p>
          <div>
            <Button
              type="button"
              variant="secondary"
              leftIcon={<HandHelping className="size-5" />}
              onClick={() => setOpen(true)}
            >
              Request support
            </Button>
          </div>
        </div>
      </Surface>
    )
  }

  return (
    <Surface as="section" className="p-6 sm:p-8" aria-labelledby="support-heading">
      <h2 id="support-heading" className="text-lg font-semibold text-ink">
        Request support
      </h2>
      <p className="mt-1 max-w-[65ch] text-sm text-muted">
        An administrator will approve and reschedule the work, ask to discuss it with you, or
        decline with an explanation.
      </p>

      <form className="mt-6 flex flex-col gap-6" onSubmit={onSubmit} noValidate>
        {/* ---------------------------------------------------------- items */}
        <fieldset className="flex flex-col gap-3">
          <legend className="text-sm font-medium text-ink">
            What does the job need? <span className="text-danger">*</span>
          </legend>

          {items.map((item, index) => (
            <div key={index} className="flex flex-col gap-2 sm:flex-row sm:items-start">
              <div className="flex-1">
                <Input
                  value={item.description}
                  onChange={(event) => patchItem(index, { description: event.target.value })}
                  placeholder="ATX power supply, 500W"
                  aria-label={`Item ${index + 1}`}
                  aria-invalid={Boolean(fieldErrors[`items.${index}.description`])}
                />
              </div>
              <Input
                type="number"
                min={1}
                value={item.quantity}
                onChange={(event) => patchItem(index, { quantity: event.target.value })}
                aria-label={`Quantity for item ${index + 1}`}
                className="sm:w-28"
              />
              {items.length > 1 && (
                <button
                  type="button"
                  onClick={() => setItems((current) => current.filter((_, i) => i !== index))}
                  className="flex size-15 shrink-0 items-center justify-center rounded-md border-2 border-control-border text-muted transition-colors duration-150 hover:bg-surface-sunken hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  <X className="size-4" aria-hidden="true" />
                  <span className="sr-only">Remove item {index + 1}</span>
                </button>
              )}
            </div>
          ))}

          {fieldErrors.items && (
            <p className="text-sm font-medium text-danger">{fieldErrors.items}</p>
          )}

          <div>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              leftIcon={<Plus className="size-4" />}
              onClick={() => setItems((current) => [...current, { ...EMPTY_ITEM }])}
            >
              Add another item
            </Button>
          </div>
        </fieldset>

        {/* ---------------------------------------------------- explanation */}
        <Field
          label="Why does the job need it?"
          required
          error={fieldErrors.explanation}
          hint="An administrator decides from this alone, so say what is blocked and what happens without it."
        >
          <Textarea
            rows={4}
            value={explanation}
            onChange={(event) => setExplanation(event.target.value)}
            placeholder="The power supply has failed and there is no spare on site. The machine cannot be returned to the lab until it is replaced."
            aria-invalid={Boolean(fieldErrors.explanation)}
          />
        </Field>

        {/* -------------------------------------------------------- evidence */}
        <div className="flex flex-col gap-3">
          <p className="text-sm font-medium text-ink">Supporting photographs (optional)</p>

          <div>
            <Button
              type="button"
              variant="secondary"
              leftIcon={<Paperclip className="size-5" />}
              onClick={() => fileInput.current?.click()}
            >
              Attach evidence
            </Button>
          </div>

          <input
            ref={fileInput}
            type="file"
            accept="image/png,image/jpeg,image/webp,application/pdf"
            capture="environment"
            multiple
            className="sr-only"
            tabIndex={-1}
            aria-hidden="true"
            onChange={addFiles}
          />

          {files.length > 0 && (
            <ul className="flex flex-col gap-2">
              {files.map((file, index) => (
                <li
                  key={`${file.name}-${index}`}
                  className="flex items-center gap-3 rounded-md border border-border bg-surface-sunken px-3 py-2"
                >
                  <span className="min-w-0 flex-1 truncate text-sm text-ink">{file.name}</span>
                  <button
                    type="button"
                    onClick={() => setFiles((current) => current.filter((_, i) => i !== index))}
                    className="flex size-11 shrink-0 items-center justify-center rounded-md text-muted transition-colors duration-150 hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <X className="size-4" aria-hidden="true" />
                    <span className="sr-only">Remove {file.name}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          {fieldErrors['evidence.0'] && (
            <p className="text-sm font-medium text-danger">{fieldErrors['evidence.0']}</p>
          )}
        </div>

        {error && (
          <Alert tone="error" title="The request was not sent">
            {error}
          </Alert>
        )}

        <div className="flex flex-wrap gap-3">
          <Button type="submit" loading={raise.isPending}>
            Send request
          </Button>
          <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
            Cancel
          </Button>
        </div>
      </form>
    </Surface>
  )
}

function collectFieldErrors(error: unknown): Record<string, string> {
  const bag = (error as { response?: { data?: { errors?: Record<string, string[]> } } } | undefined)
    ?.response?.data?.errors

  if (!bag) return {}

  return Object.fromEntries(
    Object.entries(bag).map(([field, messages]) => [field, messages[0] ?? '']),
  )
}
