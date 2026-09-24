import { CheckCircle2, ClipboardList, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Alert, Button, EmptyState, SectionHeading, Skeleton, Surface, Tabs } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ProofOfWorkCard } from '../components/ProofOfWorkCard'
import { SupportRequestCard } from '../components/SupportRequestCard'
import { useAcknowledgeSchedule, useCancelSupportRequest } from '../hooks/mutations'
import { useTechnicianSubmissions } from '../hooks/queries'
import type { SubmissionKind, SupportRequest } from '../types'

const FILTERS = [
  { value: 'all', label: 'Everything' },
  { value: 'proof_of_work', label: 'Proof of work' },
  { value: 'support_request', label: 'Support requests' },
]

/**
 * **Everything I submitted** (SRS FR-WSR-009).
 *
 * > *"…a page tracking **everything they personally submitted** — proof-of-work
 * >  records **and** support requests…"*
 *
 * Stage E shipped half of this and called it "My requests". The requirement asks
 * for one page and one navigation item covering both, which is why this replaces
 * that page rather than sitting beside it — a second nav item would have
 * contradicted the sentence it was meant to satisfy.
 *
 * ── Two kinds, one column, distinct cards ──────────────────────────────────
 *
 * Both kinds share the timeline because that is how they happened: a technician
 * scans a machine, submits proof, and — when the job cannot be finished — raises
 * a request. Splitting them into two lists would make the reader reassemble the
 * order themselves. They get visibly different cards, and each entry is labelled,
 * so the shared column never blurs what a row is.
 *
 * ── The actions are only the ones the server would accept ──────────────────
 *
 * `is_undecided` and an unacknowledged reschedule both come from the payload, so
 * a button never appears for a move the API would refuse. Proof-of-work entries
 * carry no actions at all: a submission is a record of something that happened,
 * and the job it belongs to is edited on the maintenance page.
 */
export default function MySubmissionsPage() {
  useDocumentMeta({ title: 'My submissions' })

  const [filter, setFilter] = useState<string>('all')
  const [error, setError] = useState<string | null>(null)

  const kind = filter === 'all' ? undefined : (filter as SubmissionKind)
  const query = useTechnicianSubmissions(kind)

  const cancel = useCancelSupportRequest()
  const acknowledge = useAcknowledgeSchedule()

  async function run(action: () => Promise<unknown>) {
    setError(null)
    try {
      await action()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const submissions = query.data?.data ?? []

  return (
    <div className="flex flex-col gap-6">
      <SectionHeading
        as="h1"
        title="My submissions"
        lead="The proof of work you recorded on site, and the support you asked for — newest first."
      />

      <Tabs tabs={FILTERS} value={filter} onChange={setFilter} />

      {error && (
        <Alert tone="error" title="That did not work">
          {error}
        </Alert>
      )}

      {query.isPending ? (
        <div className="flex flex-col gap-4">
          {[0, 1].map((n) => (
            <Surface key={n} className="p-6">
              <Skeleton className="h-5 w-48" />
              <Skeleton className="mt-3 h-4 w-full max-w-lg" />
              <Skeleton className="mt-2 h-4 w-full max-w-md" />
            </Surface>
          ))}
        </div>
      ) : query.isError ? (
        <Alert tone="error" title="Your submissions could not be loaded">
          {getErrorMessage(query.error)}
        </Alert>
      ) : submissions.length === 0 ? (
        <EmptyState
          icon={<ClipboardList className="size-6" />}
          title={filter === 'all' ? 'You have not submitted anything yet' : 'Nothing here'}
          description={
            filter === 'all'
              ? 'Scan the label on a machine you are working on to record what you did, or to ask for a part the job needs. Everything you send appears here.'
              : 'Nothing of yours is of this kind.'
          }
        />
      ) : (
        <ul className="flex flex-col gap-4">
          {submissions.map((entry, index) =>
            entry.kind === 'proof_of_work' ? (
              <li key={`proof-${entry.proof_of_work.id}-${index}`}>
                <SubmissionLabel>Proof of work</SubmissionLabel>
                <ProofOfWorkCard submission={entry.proof_of_work} />
              </li>
            ) : (
              <li key={`request-${entry.support_request.id}-${index}`}>
                <SubmissionLabel>Support request</SubmissionLabel>
                <SupportRequestCard
                  request={entry.support_request}
                  actions={<TechnicianActions request={entry.support_request} onRun={run} />}
                />
              </li>
            ),
          )}
        </ul>
      )}
    </div>
  )

  function TechnicianActions({
    request,
    onRun,
  }: {
    request: SupportRequest
    onRun: (action: () => Promise<unknown>) => Promise<void>
  }) {
    const needsAcknowledgement =
      request.status === 'approved' &&
      request.decision.rescheduled_to !== null &&
      request.decision.acknowledged_at === null

    return (
      <>
        {needsAcknowledgement && (
          <Button
            type="button"
            size="sm"
            leftIcon={<CheckCircle2 className="size-4" />}
            loading={acknowledge.isPending}
            onClick={() => void onRun(() => acknowledge.mutateAsync(request.id))}
          >
            Got it — I&rsquo;ll work to the new date
          </Button>
        )}

        {request.is_undecided && (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            leftIcon={<XCircle className="size-4" />}
            loading={cancel.isPending}
            onClick={() => void onRun(() => cancel.mutateAsync({ id: request.id }))}
          >
            Withdraw
          </Button>
        )}
      </>
    )
  }
}

/**
 * The kind, said in words above each card.
 *
 * Not colour, not an icon alone: the two kinds are the one thing a reader must
 * never have to guess on this page, and a text label is what survives grayscale,
 * colour-blindness and a screen reader.
 */
function SubmissionLabel({ children }: { children: React.ReactNode }) {
  return <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted">{children}</p>
}
