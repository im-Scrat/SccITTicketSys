import { isAxiosError } from 'axios'
import { useEffect, useId, useRef, useState } from 'react'
import { Alert, Badge, Button, Field, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useChangeTicketStatus, useReportNotFixed } from '../hooks/mutations'
import type { ReporterOutcome, TicketAiAnalysisEnvelope } from '../types'

interface TicketAiTroubleshootingProps {
  ticketId: string
  /** The ticket's current status slug — outcomes are only offered while `open`. */
  statusSlug: string
  /** Whether this ticket names a PC, so the provenance line is accurate. */
  hasPcUnit: boolean
  analysis: TicketAiAnalysisEnvelope | undefined
  /** When `analysis` was last fetched — see `OutcomeArea`'s optimistic message. */
  analysisUpdatedAt: number
  isError: boolean
  error: unknown
  onRetry: () => void
  canMarkFixed: boolean
  canReportNotFixed: boolean
  reopenWindowDays?: number
}

/**
 * The reporter's "try this first" panel (WP-I analysis + WP-J outcome).
 *
 * **Advisory by construction.** The header says the steps are AI-generated; a
 * below-threshold — or unknown — confidence adds a plain-language caution
 * rather than hiding the steps; and nothing here changes the ticket on its
 * own. Both outcomes are the reporter's explicit, confirmed choice: FIXED is
 * the ordinary `open → resolved` move, NOT FIXED keeps the ticket open and back
 * in the IT team's queue.
 *
 * **Inline, not modal.** The confirmation and the optional note expand in
 * place, beside the steps the person just tried — product UI exhausts inline
 * options before a modal, and the shared `Modal` does not yet manage focus.
 * Focus moves into the disclosure when it opens, back to its trigger on
 * cancel, and onto the recorded outcome once it is confirmed.
 *
 * Renders nothing when there is no analysis (not run yet, AI not configured,
 * or not the caller's to see): the panel is an optional aid, and an empty box
 * on every ticket would be noise.
 */
export function TicketAiTroubleshooting({
  ticketId,
  statusSlug,
  hasPcUnit,
  analysis,
  analysisUpdatedAt,
  isError,
  error,
  onRetry,
  canMarkFixed,
  canReportNotFixed,
  reopenWindowDays,
}: TicketAiTroubleshootingProps) {
  const headingId = useId()

  if (isError) {
    const status = isAxiosError(error) ? error.response?.status : undefined
    // Not the caller's to see, or not there: the panel simply does not apply.
    if (status === 403 || status === 404) return null

    return (
      <section
        aria-labelledby={headingId}
        className="flex flex-col gap-3 rounded-lg border-2 border-border bg-surface p-6"
      >
        <h2 id={headingId} className="text-base font-bold text-ink-strong">
          Things to try first
        </h2>
        {status === 429 ? (
          <p className="text-sm text-muted">
            Suggested steps are paused for a little while. Your report is with the IT team either
            way.
          </p>
        ) : (
          <div className="flex flex-wrap items-center gap-3">
            <p className="text-sm text-muted">Suggested steps couldn’t be loaded right now.</p>
            <Button variant="ghost" size="sm" onClick={onRetry}>
              Try again
            </Button>
          </div>
        )}
      </section>
    )
  }

  // Narrow on the top-level `data` discriminant (TS does not narrow a union on
  // the nested `meta.available`).
  if (!analysis || analysis.data === null) return null

  const result = analysis.data
  // The server already sends these ordered; sorting here keeps the rendered
  // order and the printed step numbers from ever disagreeing.
  const steps = [...(result.recommendations ?? [])].sort((a, b) => a.step - b.step)
  // Unknown confidence is treated as "not known to meet the bar", never as fact.
  const lowConfidence = result.meets_confidence_threshold !== true

  return (
    <section
      aria-labelledby={headingId}
      className="flex flex-col gap-5 rounded-lg border-2 border-border bg-surface p-6"
    >
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-3">
          <h2 id={headingId} className="text-base font-bold text-ink-strong">
            Things to try first
          </h2>
          <Badge tone="neutral">AI-generated</Badge>
        </div>
        <p className="measure text-sm text-muted">
          Suggested automatically from your report
          {hasPcUnit ? ' and this computer’s repair history' : ''}. The IT team still sees your
          report — trying these is optional.
        </p>
      </header>

      {lowConfidence && (
        <Alert tone="info" title="These are ideas, not instructions">
          The assistant wasn’t confident about this one. Only try the steps you’re comfortable with.
        </Alert>
      )}

      {result.summary && <p className="measure text-base text-ink">{result.summary}</p>}

      {result.technician_required && (
        <p className="measure text-sm text-muted">
          This may need a technician in the end. Don’t open the computer or move cables you’re
          unsure about.
        </p>
      )}

      {steps.length > 0 && (
        // role="list" keeps list semantics that some screen readers drop once
        // list markers are restyled; the order is the point here.
        <ol role="list" className="flex flex-col gap-3">
          {steps.map((step) => (
            <li key={step.step} className="flex gap-3">
              <span
                aria-hidden="true"
                className="tnum w-6 shrink-0 text-right text-base font-semibold text-muted"
              >
                {step.step}.
              </span>
              <span className="measure text-base text-ink">{step.text}</span>
            </li>
          ))}
        </ol>
      )}

      <OutcomeArea
        ticketId={ticketId}
        statusSlug={statusSlug}
        serverOutcome={analysis.meta.reporter_outcome}
        analysisUpdatedAt={analysisUpdatedAt}
        canMarkFixed={canMarkFixed}
        canReportNotFixed={canReportNotFixed}
        reopenWindowDays={reopenWindowDays}
      />
    </section>
  )
}

/**
 * "Did this fix it?" — and, once answered, what was said.
 *
 * The server is the record: `serverOutcome` comes from the reporter's own
 * timeline rows for the ticket's current open period, and `canMarkFixed` /
 * `canReportNotFixed` are the policy's answer. The one piece of local state is
 * `confirmed`, which shows the answer immediately while the post-write
 * refetch is in flight — honored only until fresh analysis data arrives
 * (`analysisUpdatedAt` changes), after which the server's view wins. That
 * keeps the old buttons from flashing back, without letting a stale local
 * answer outlive a later reopen.
 */
function OutcomeArea({
  ticketId,
  statusSlug,
  serverOutcome,
  analysisUpdatedAt,
  canMarkFixed,
  canReportNotFixed,
  reopenWindowDays,
}: {
  ticketId: string
  statusSlug: string
  serverOutcome: ReporterOutcome | null
  analysisUpdatedAt: number
  canMarkFixed: boolean
  canReportNotFixed: boolean
  reopenWindowDays?: number
}) {
  const baseId = useId()
  const fixedTriggerId = `${baseId}-fixed`
  const notFixedTriggerId = `${baseId}-not-fixed`
  const recordedId = `${baseId}-recorded`

  const markFixed = useChangeTicketStatus(ticketId)
  const reportNotFixed = useReportNotFixed(ticketId)

  const [pending, setPending] = useState<ReporterOutcome | null>(null)
  const [confirmed, setConfirmed] = useState<{ outcome: ReporterOutcome; at: number } | null>(null)
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const noteRef = useRef<HTMLTextAreaElement>(null)

  // Focus into the disclosure when it opens.
  useEffect(() => {
    if (pending) noteRef.current?.focus()
  }, [pending])

  const isOpen = statusSlug === 'open'
  const fromServer: ReporterOutcome | null =
    serverOutcome === 'not_fixed' && isOpen
      ? 'not_fixed'
      : serverOutcome === 'fixed' && statusSlug === 'resolved'
        ? 'fixed'
        : null
  const optimistic = confirmed && confirmed.at === analysisUpdatedAt ? confirmed.outcome : null
  const recorded = fromServer ?? optimistic

  const offerFixed = isOpen && canMarkFixed && recorded !== 'fixed'
  const offerNotFixed = isOpen && canReportNotFixed && recorded === null

  if (!recorded && !offerFixed && !offerNotFixed) return null

  const busy = markFixed.isPending || reportNotFixed.isPending

  const open = (which: ReporterOutcome) => {
    setError(null)
    setNote('')
    setPending(which)
  }

  const cancel = () => {
    const trigger = pending === 'fixed' ? fixedTriggerId : notFixedTriggerId
    setPending(null)
    setNote('')
    setError(null)
    // After React removes the disclosure, return focus to what opened it.
    requestAnimationFrame(() => document.getElementById(trigger)?.focus())
  }

  const confirm = async () => {
    if (!pending) return
    setError(null)
    const remarks = note.trim() || undefined
    try {
      if (pending === 'fixed') {
        await markFixed.mutateAsync({ status: 'resolved', remarks })
      } else {
        await reportNotFixed.mutateAsync({ remarks })
      }
      setConfirmed({ outcome: pending, at: analysisUpdatedAt })
      setPending(null)
      setNote('')
      // The buttons just used are gone; land focus on what was recorded rather
      // than dropping a keyboard or screen-reader user on <body>.
      requestAnimationFrame(() => document.getElementById(recordedId)?.focus())
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-4 border-t border-border pt-5">
      {recorded && (
        <p
          id={recordedId}
          tabIndex={-1}
          role="status"
          className="measure rounded-md bg-surface-sunken px-4 py-3 text-sm text-ink"
        >
          {recorded === 'fixed'
            ? 'You marked this as fixed. If the problem comes back, you can reopen the ticket below.'
            : 'You told the IT team these steps didn’t fix it. It’s back in the queue for a technician — you don’t need to do anything else.'}
        </p>
      )}

      {(offerFixed || offerNotFixed) && pending === null && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-semibold text-ink-strong">
            {recorded === 'not_fixed' ? 'Did it start working after all?' : 'Did this fix it?'}
          </p>
          <div className="flex flex-wrap gap-3">
            {offerFixed && (
              <Button
                id={fixedTriggerId}
                variant="secondary"
                disabled={busy}
                onClick={() => open('fixed')}
              >
                {recorded === 'not_fixed' ? 'Yes, it’s working now' : 'This fixed it'}
              </Button>
            )}
            {offerNotFixed && (
              <Button
                id={notFixedTriggerId}
                variant="secondary"
                disabled={busy}
                onClick={() => open('not_fixed')}
              >
                Still not working
              </Button>
            )}
          </div>
        </div>
      )}

      {pending && (
        <div
          role="group"
          aria-label={
            pending === 'fixed'
              ? 'Mark your report as fixed'
              : 'Tell the IT team it is still not working'
          }
          className="flex flex-col gap-4 rounded-md border border-border bg-surface-sunken p-4"
        >
          <p className="measure text-sm text-ink">
            {pending === 'fixed'
              ? `This marks your report as fixed.${
                  reopenWindowDays !== undefined
                    ? ` If the problem comes back, you can reopen it for ${reopenWindowDays} days after it closes.`
                    : ''
                }`
              : 'Your report stays open and goes back to the IT team’s queue for a technician.'}
          </p>

          {error && <Alert tone="error">{error}</Alert>}

          <Field
            label={
              pending === 'fixed'
                ? 'What fixed it? (optional)'
                : 'What happened when you tried? (optional)'
            }
            hint="Shared with the IT team on your ticket’s history."
          >
            <Textarea
              ref={noteRef}
              value={note}
              rows={3}
              maxLength={2000}
              onChange={(event) => setNote(event.target.value)}
            />
          </Field>

          <div className="flex flex-wrap gap-3">
            <Button loading={busy} onClick={confirm}>
              {pending === 'fixed' ? 'Mark as fixed' : 'Send to the IT team'}
            </Button>
            <Button variant="ghost" disabled={busy} onClick={cancel}>
              Cancel
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
