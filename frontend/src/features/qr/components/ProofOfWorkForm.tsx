import { Camera, CheckCircle2, ImageUp, X } from 'lucide-react'
import { type ChangeEvent, type FormEvent, useId, useRef, useState } from 'react'
import { Alert, Button, Field, Select, Surface, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { cn } from '@/lib/cn'
import { useSubmitProofOfWork } from '../hooks/mutations'
import { selectionTargetsFromError } from '../lib/refusals'
import type { EvidenceStage, ProofOutcome, ProofResult, WorkTarget } from '../types'

const STAGES: { value: EvidenceStage; label: string }[] = [
  { value: 'before', label: 'Before the work' },
  { value: 'during', label: 'During the work' },
  { value: 'after', label: 'After the work' },
]

const OUTCOMES: { value: ProofOutcome; label: string; hint: string }[] = [
  { value: 'in_progress', label: 'Still working', hint: 'The job stays open and assigned to you.' },
  {
    value: 'on_hold',
    label: 'Blocked',
    hint: 'You cannot finish yet — a part or a decision is missing.',
  },
  {
    value: 'completed',
    label: 'Finished',
    hint: 'The job is done and the machine is back in service.',
  },
]

/**
 * Submitting proof that work was performed (SRS FR-MNT-009/010/012).
 *
 * ── Why this is a form and not a wizard ────────────────────────────────────
 *
 * The whole submission is one server operation, and splitting it into steps
 * would create client-side state that can disagree with the server about what
 * has been recorded. Someone crouched beside a machine gets one screen, fills
 * three things in, and taps once.
 *
 * ── Photographs first, because that is the order of the job ────────────────
 *
 * Evidence sits at the top: the technician has just finished, the phone is
 * already in their hand, and the camera is the thing they came here to use.
 * `capture="environment"` opens the rear camera directly rather than a file
 * browser — on a phone that removes an entire detour, and on a desktop the
 * attribute is ignored and the picker behaves normally.
 *
 * ── The client never decides anything ──────────────────────────────────────
 *
 * Which record this lands on, whether the caller may write it, and whether it
 * may be completed are all the server's, re-checked inside its transaction.
 * The chooser below is a convenience for picking between jobs the server
 * already said were available; sending a different one simply gets refused.
 */
export function ProofOfWorkForm({
  code,
  scanId,
  targets,
  mayOpenRecord,
  onSubmitted,
}: {
  code: string
  /** The physical scan this proof belongs to (FR-MNT-012). */
  scanId: string
  targets: WorkTarget[]
  mayOpenRecord: boolean
  onSubmitted: (result: ProofResult) => void
}) {
  const submit = useSubmitProofOfWork(code)
  const fileInput = useRef<HTMLInputElement>(null)
  const evidenceHintId = useId()

  const [maintenanceId, setMaintenanceId] = useState(targets.length === 1 ? targets[0].id : '')
  const [resolution, setResolution] = useState('')
  const [outcome, setOutcome] = useState<ProofOutcome>('completed')
  const [stage, setStage] = useState<EvidenceStage>('after')
  const [files, setFiles] = useState<File[]>([])
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  // Populated when the server refuses to choose between several open jobs — the
  // one case where the chooser appears after a submission rather than before.
  const [pendingChoice, setPendingChoice] = useState<WorkTarget[] | null>(null)

  const choices = pendingChoice ?? targets
  const mustChoose = choices.length > 1
  const nothingToWorkOn = choices.length === 0 && !mayOpenRecord

  function addFiles(event: ChangeEvent<HTMLInputElement>) {
    const picked = Array.from(event.target.files ?? [])
    event.target.value = '' // let the same photograph be re-picked after an error

    if (picked.length === 0) return

    setFieldErrors((current) => ({ ...current, evidence: '' }))
    setFiles((current) => [...current, ...picked].slice(0, 10))
  }

  function removeFile(index: number) {
    setFiles((current) => current.filter((_, position) => position !== index))
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})

    try {
      const result = await submit.mutateAsync({
        scanId,
        maintenanceId: maintenanceId || undefined,
        resolution,
        outcome,
        evidenceStage: stage,
        evidence: files,
      })

      setPendingChoice(null)
      onSubmitted(result)
    } catch (caught) {
      const selection = selectionTargetsFromError(caught)

      if (selection !== null) {
        // The server found several open jobs and would not guess. Show them,
        // and keep everything the technician has already typed.
        setPendingChoice(selection)
        setMaintenanceId('')
      }

      setError(getErrorMessage(caught))
      setFieldErrors(collectFieldErrors(caught))
    }
  }

  if (nothingToWorkOn) {
    return (
      <Surface className="p-6 sm:p-8">
        <h2 className="text-lg font-semibold text-ink">Nothing to record against</h2>
        <p className="mt-2 max-w-[65ch] text-sm leading-relaxed text-pretty text-muted">
          This machine has no open maintenance record you can work on, and your account cannot open
          one. Proof of work attaches to a scheduled or in-progress job — ask an administrator to
          schedule the work, then scan the label again.
        </p>
      </Surface>
    )
  }

  return (
    <Surface as="section" className="p-6 sm:p-8" aria-labelledby="proof-heading">
      <h2 id="proof-heading" className="text-lg font-semibold text-ink">
        Record what you did
      </h2>
      <p className="mt-1 text-sm text-muted">
        {choices.length === 0
          ? 'This machine has no open job, so submitting will open one and attach your work to it.'
          : 'Your work is attached to the job below and kept with its evidence.'}
      </p>

      <form className="mt-6 flex flex-col gap-6" onSubmit={onSubmit} noValidate>
        {mustChoose && (
          <Field
            label="Which job is this?"
            required
            error={fieldErrors.maintenance_id}
            hint="This machine has more than one job open. Pick the one your work belongs to."
          >
            <Select
              value={maintenanceId}
              onChange={(event) => setMaintenanceId(event.target.value)}
              aria-invalid={Boolean(fieldErrors.maintenance_id)}
            >
              <option value="">Choose a job…</option>
              {choices.map((target) => (
                <option key={target.id} value={target.id}>
                  {target.title} — {target.status_label}
                  {target.type ? ` (${target.type})` : ''}
                </option>
              ))}
            </Select>
          </Field>
        )}

        {/* ---------------------------------------------------- evidence */}
        <fieldset className="flex flex-col gap-3">
          <legend className="text-sm font-medium text-ink">
            Photographs <span className="text-danger">*</span>
          </legend>
          <p id={evidenceHintId} className="text-sm text-muted">
            At least one photograph is required. JPEG, PNG, WebP or PDF, up to ten items.
          </p>

          <div className="flex flex-col gap-3 sm:flex-row">
            <Select
              value={stage}
              onChange={(event) => setStage(event.target.value as EvidenceStage)}
              aria-label="When these photographs were taken"
              className="sm:max-w-56"
            >
              {STAGES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>

            <Button
              type="button"
              variant="secondary"
              leftIcon={<Camera className="size-5" />}
              onClick={() => fileInput.current?.click()}
              aria-describedby={evidenceHintId}
            >
              Add photographs
            </Button>
          </div>

          {/*
            `capture="environment"` asks a phone for the rear camera directly.
            Desktop browsers ignore it and show the normal picker, so there is
            no second control to maintain.
          */}
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
                  key={`${file.name}-${file.lastModified}-${index}`}
                  className="flex items-center gap-3 rounded-md border border-border bg-surface-sunken px-3 py-2"
                >
                  <ImageUp className="size-4 shrink-0 text-muted" aria-hidden="true" />
                  <span className="min-w-0 flex-1 truncate text-sm text-ink">{file.name}</span>
                  <button
                    type="button"
                    onClick={() => removeFile(index)}
                    className="flex size-11 shrink-0 items-center justify-center rounded-md text-muted transition-colors duration-150 hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <X className="size-4" aria-hidden="true" />
                    <span className="sr-only">Remove {file.name}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          {fieldErrors.evidence && (
            <p className="text-sm font-medium text-danger">{fieldErrors.evidence}</p>
          )}
          {fieldErrors['evidence.0'] && (
            <p className="text-sm font-medium text-danger">{fieldErrors['evidence.0']}</p>
          )}
        </fieldset>

        {/* -------------------------------------------------- what was done */}
        <Field
          label="What you did"
          required
          error={fieldErrors.resolution}
          hint="A short account of the work — enough for the next person to understand what was changed."
        >
          <Textarea
            rows={4}
            value={resolution}
            onChange={(event) => setResolution(event.target.value)}
            placeholder="Replaced the power supply and reseated the RAM. Machine boots and passes a memory test."
            aria-invalid={Boolean(fieldErrors.resolution)}
          />
        </Field>

        {/* ------------------------------------------------------- outcome */}
        <fieldset className="flex flex-col gap-3">
          <legend className="text-sm font-medium text-ink">
            Where does the job stand? <span className="text-danger">*</span>
          </legend>

          <div className="grid gap-2 sm:grid-cols-3">
            {OUTCOMES.map((option) => (
              <label
                key={option.value}
                className={cn(
                  'flex cursor-pointer flex-col gap-1 rounded-md border-2 p-4 transition-colors duration-150',
                  'has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary',
                  outcome === option.value
                    ? 'border-primary bg-primary-subtle'
                    : 'border-control-border bg-surface hover:bg-surface-sunken',
                )}
              >
                <span className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="outcome"
                    value={option.value}
                    checked={outcome === option.value}
                    onChange={() => setOutcome(option.value)}
                    className="size-4 accent-[var(--primary)]"
                  />
                  <span className="text-sm font-semibold text-ink">{option.label}</span>
                </span>
                <span className="text-xs leading-relaxed text-muted">{option.hint}</span>
              </label>
            ))}
          </div>

          {fieldErrors.outcome && (
            <p className="text-sm font-medium text-danger">{fieldErrors.outcome}</p>
          )}
          {fieldErrors.status && (
            <Alert tone="warning" title="This job cannot be finished yet">
              {fieldErrors.status}
            </Alert>
          )}
        </fieldset>

        {error && !fieldErrors.status && (
          <Alert tone="error" title="The submission was not recorded">
            {error}
          </Alert>
        )}

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <Button
            type="submit"
            size="lg"
            loading={submit.isPending}
            leftIcon={<CheckCircle2 className="size-5" />}
            className="sm:w-auto"
          >
            Submit proof of work
          </Button>
          <p className="text-xs text-muted">
            Submitting twice is safe — a repeat updates the same job instead of opening a second.
          </p>
        </div>
      </form>
    </Surface>
  )
}

/**
 * Laravel's `errors` bag, flattened to the first message per field.
 *
 * `status` is included deliberately: that is the key `MaintenanceLifecycle`
 * uses for a blocked completion, and its message ("one required checklist item
 * is still outstanding") is the most useful sentence the server can send.
 */
function collectFieldErrors(error: unknown): Record<string, string> {
  const bag = (error as { response?: { data?: { errors?: Record<string, string[]> } } } | undefined)
    ?.response?.data?.errors

  if (!bag) return {}

  return Object.fromEntries(
    Object.entries(bag).map(([field, messages]) => [field, messages[0] ?? '']),
  )
}
