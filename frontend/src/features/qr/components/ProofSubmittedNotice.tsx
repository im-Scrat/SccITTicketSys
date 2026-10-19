import { CheckCircle2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Button, ButtonLink, Surface } from '@/components/ui'
import type { ProofResult } from '../types'

/**
 * The confirmation, and specifically **what happened** (SRS FR-MNT-009/012).
 *
 * A submission can land three different ways, and they are not interchangeable
 * to the person who just tapped the button:
 *
 *   attached  — the work went onto the job that was already open
 *   opened    — nothing was open, so a record was created for it
 *   replayed  — this scan had already been submitted; the job was updated
 *
 * The third is the one worth saying out loud. A technician who taps twice on a
 * bad signal deserves to be told that the second tap changed nothing rather
 * than left to wonder whether they have now filed the job twice — which is
 * exactly the anxiety FR-MNT-012 exists to remove, and a silent success would
 * leave in place.
 *
 * Deduplicated photographs are reported for the same reason: "2 already
 * recorded" is reassurance, and silence about them would read as loss.
 */
export function ProofSubmittedNotice({
  result,
  onRecordMore,
}: {
  result: ProofResult
  onRecordMore: () => void
}) {
  const finished = result.maintenance.status === 'completed'

  return (
    <Surface className="p-6 sm:p-8">
      <div className="flex flex-col gap-5">
        <span
          className="flex size-12 items-center justify-center rounded-md bg-success-subtle text-success-strong"
          aria-hidden="true"
        >
          <CheckCircle2 className="size-6" />
        </span>

        <div className="flex flex-col gap-2">
          {/*
            role="status" rather than an alert: this is a successful outcome, and
            a screen reader should hear it without the page being interrupted.
          */}
          <h2 className="text-xl font-semibold text-balance text-ink" role="status">
            {result.replayed
              ? 'Already recorded — nothing was duplicated'
              : finished
                ? 'Work recorded and the job is finished'
                : 'Work recorded'}
          </h2>

          <p className="max-w-[65ch] text-sm leading-relaxed text-pretty text-muted">
            {describe(result)}
          </p>
        </div>

        <dl className="grid gap-x-8 gap-y-3 border-t border-border pt-5 sm:grid-cols-2">
          <Row label="Job">{result.maintenance.title}</Row>
          <Row label="Status">{result.maintenance.status_label}</Row>
          {result.maintenance.ticket && <Row label="Ticket">{result.maintenance.ticket}</Row>}
          <Row label="Evidence on file">
            {result.evidence.length === 1 ? '1 item' : `${result.evidence.length} items`}
          </Row>
        </dl>

        <div className="flex flex-wrap gap-3 pt-1">
          <ButtonLink to={`/app/maintenance/${result.maintenance.id}`} variant="secondary">
            Open the full record
          </ButtonLink>

          {!finished && (
            <Button type="button" variant="ghost" onClick={onRecordMore}>
              Record more work
            </Button>
          )}

          <Link
            to="/app/maintenance"
            className="inline-flex h-15 items-center px-2 text-sm font-medium text-muted underline-offset-4 hover:text-ink hover:underline"
          >
            Back to my queue
          </Link>
        </div>
      </div>
    </Surface>
  )
}

function describe(result: ProofResult): string {
  const parts: string[] = []

  if (result.replayed) {
    parts.push(
      'This scan had already been submitted, so the same job was updated rather than a second one opened.',
    )
  } else if (result.created) {
    parts.push('This machine had no open job, so one was opened and your work attached to it.')
  } else {
    parts.push('Your work was attached to the job that was already open on this machine.')
  }

  if (result.evidence_added > 0) {
    parts.push(
      result.evidence_added === 1
        ? '1 photograph was stored.'
        : `${result.evidence_added} photographs were stored.`,
    )
  }

  if (result.evidence_skipped > 0) {
    parts.push(
      result.evidence_skipped === 1
        ? '1 was already on file and was not stored twice.'
        : `${result.evidence_skipped} were already on file and were not stored twice.`,
    )
  }

  return parts.join(' ')
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-medium tracking-wide text-muted">{label}</dt>
      <dd className="text-sm font-medium text-ink">{children}</dd>
    </div>
  )
}
