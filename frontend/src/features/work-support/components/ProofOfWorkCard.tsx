import { Camera, ClipboardCheck, FileText, Monitor } from 'lucide-react'
import { Surface } from '@/components/ui'
import { cn } from '@/lib/cn'
import { formatDateTime } from '@/lib/datetime'
import type { ProofOfWorkSubmission } from '../types'

/**
 * A proof-of-work submission, as the technician's history lists it
 * (SRS FR-WSR-009).
 *
 * ── It answers one question ────────────────────────────────────────────────
 *
 * *"Did my submission save, and what happened to that job?"* — so it carries the
 * machine, the job's current status, what the technician wrote, and how many
 * photographs are on file. It is not a maintenance detail card and deliberately
 * does not grow into one: the maintenance module already has that page, and
 * duplicating it here would mean two places to keep a projection narrow.
 *
 * ── Evidence is counted, not opened ────────────────────────────────────────
 *
 * Unlike a support request's attachments — which an administrator must be able
 * to open in order to decide — proof-of-work images belong to a maintenance
 * record, and that module's own authorized download route already serves them
 * behind its own policy. Adding a second way in from here would be a second
 * boundary over the same bytes, so this links to the record instead.
 */
export function ProofOfWorkCard({ submission }: { submission: ProofOfWorkSubmission }) {
  return (
    <Surface as="article" className="p-5 sm:p-6">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 flex-col gap-1">
            <p className="flex items-center gap-2 text-sm font-semibold text-ink">
              <Monitor className="size-4 shrink-0 text-muted" aria-hidden="true" />
              <span className="font-mono">{submission.pc_unit?.unit_code ?? 'Unknown unit'}</span>
              {submission.pc_unit?.pc_name && (
                <span className="truncate font-normal text-muted">
                  · {submission.pc_unit.pc_name}
                </span>
              )}
            </p>
            <p className="text-xs text-muted">
              {submission.title}
              {submission.type ? ` · ${submission.type}` : ''}
              {submission.ticket ? ` · Ticket ${submission.ticket}` : ''}
            </p>
          </div>

          <span
            className={cn(
              'inline-flex items-center gap-2 rounded-full border-2 px-3 py-1 text-xs font-semibold',
              submission.status === 'completed'
                ? 'border-success-strong bg-success-subtle text-success-strong'
                : 'border-control-border bg-surface-sunken text-ink',
            )}
          >
            {submission.status_label}
          </span>
        </div>

        {submission.resolution && (
          <div className="flex flex-col gap-0.5">
            <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted">
              <ClipboardCheck className="size-3.5" aria-hidden="true" />
              What you recorded
            </p>
            <p className="max-w-[70ch] text-sm leading-relaxed text-pretty text-ink">
              {submission.resolution}
            </p>
          </div>
        )}

        <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
          {submission.evidence.length > 0 && (
            <span className="inline-flex items-center gap-1.5">
              {submission.evidence.some((item) => item.kind === 'image') ? (
                <Camera className="size-3.5" aria-hidden="true" />
              ) : (
                <FileText className="size-3.5" aria-hidden="true" />
              )}
              {submission.evidence.length}{' '}
              {submission.evidence.length === 1 ? 'item of evidence' : 'items of evidence'}
              {' · '}
              {[...new Set(submission.evidence.map((item) => item.stage))].join(', ')}
            </span>
          )}
          {submission.completed_at && (
            <span>Completed {formatDateTime(submission.completed_at)}</span>
          )}
        </div>
      </div>
    </Surface>
  )
}
