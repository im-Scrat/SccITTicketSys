import { ClipboardList, Cpu, Ticket } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Alert, Skeleton, Surface } from '@/components/ui'
import { RaiseSupportRequestForm } from '@/features/work-support/components/RaiseSupportRequestForm'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDateTime } from '@/lib/datetime'
import { ProofOfWorkForm } from '../components/ProofOfWorkForm'
import { ProofSubmittedNotice } from '../components/ProofSubmittedNotice'
import { ScannedPcIdentity } from '../components/ScannedPcIdentity'
import { ScanRefusalNotice } from '../components/ScanRefusalNotice'
import { useScannedPanel, useWorkTargets } from '../hooks/queries'
import { useScanCode } from '../hooks/mutations'
import { refusalFor, refusalFromError } from '../lib/refusals'
import type { ProofResult, ScannedMaintenance, ScannedPcUnit } from '../types'
import { useEffect, useRef } from 'react'

/**
 * The scanned machine's working surface (SRS FR-QR-012, FR-MNT-009/010).
 *
 * ── The page is one column, in the order of the job ────────────────────────
 *
 *   1. Which machine is this?      — confirmed at arm's length
 *   2. Record what you did         — the reason the technician is here
 *   3. Reference detail            — the job's notes, the spec, what is fitted
 *
 * Deliberately **not** a dashboard of cards. There is one task on this screen,
 * and putting the specification above the form would put reference material
 * between someone and the thing they came to do.
 *
 * ── A fresh scan is taken on arrival ───────────────────────────────────────
 *
 * The panel is reached after sign-in, or by returning to the page later, and a
 * proof submission has to quote a scan that is this caller's and current
 * (FR-MNT-012). Rather than smuggle the earlier scan id through the URL — where
 * it would be a caller-supplied claim, which DD-48 keeps out of this flow — the
 * page records its own scan and uses the identifier the server hands back.
 *
 * The panel data itself comes from a **second** request that re-resolves the
 * code and re-authorizes from scratch. Nothing about the first scan is carried
 * forward as a permission.
 */
export default function ScanPanelPage() {
  const { code = '' } = useParams<{ code: string }>()

  const panel = useScannedPanel(code)
  const work = useWorkTargets(code, panel.isSuccess)
  const scan = useScanCode()

  const [scanId, setScanId] = useState<string | null>(null)
  const [submitted, setSubmitted] = useState<ProofResult | null>(null)
  const [supportSent, setSupportSent] = useState(false)

  useDocumentMeta({ title: panel.data ? panel.data.unit_code : 'Scanned equipment' })

  // One scan per visit, for the same reason the landing page guards it: a scan
  // is a logged physical event, and StrictMode mounts effects twice.
  const scanned = useRef<string | null>(null)

  useEffect(() => {
    if (code === '' || scanned.current === code) return

    scanned.current = code

    scan
      .mutateAsync(code)
      .then((outcome) => setScanId(outcome.scan_id ?? null))
      .catch(() => setScanId(null))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code])

  if (panel.isPending) {
    return <PanelSkeleton />
  }

  if (panel.isError) {
    const refusal = refusalFromError(panel.error) ?? refusalFor(undefined)

    return (
      <div className="mx-auto max-w-3xl">
        <ScanRefusalNotice refusal={refusal} />
      </div>
    )
  }

  const unit = panel.data

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <ScannedPcIdentity unit={unit} />

      {submitted !== null ? (
        <ProofSubmittedNotice
          result={submitted}
          onRecordMore={() => {
            setSubmitted(null)
            // A further submission is a further scan: the previous one is bound
            // to the record now, and replaying it would update rather than add.
            scanned.current = null
            setScanId(null)
            scan
              .mutateAsync(code)
              .then((outcome) => setScanId(outcome.scan_id ?? null))
              .catch(() => setScanId(null))
          }}
        />
      ) : scanId === null ? (
        <Surface className="p-6">
          <Skeleton className="h-6 w-48" />
          <Skeleton className="mt-3 h-4 w-full max-w-md" />
        </Surface>
      ) : (
        <ProofOfWorkForm
          code={code}
          scanId={scanId}
          targets={work.data?.targets ?? []}
          mayOpenRecord={work.data?.may_open_record ?? false}
          onSubmitted={setSubmitted}
        />
      )}

      {/*
        Asking for help finishing the job (FR-WSR-001), below proof of work
        because they are different moments: proof is what a technician submits
        when the job is done, a support request is what they submit when it
        cannot be. The form is collapsed until it is wanted.
      */}
      <RaiseSupportRequestForm
        code={code}
        maintenanceId={work.data?.targets.length === 1 ? work.data.targets[0].id : undefined}
        onSubmitted={() => setSupportSent(true)}
      />

      {supportSent && (
        <Alert tone="success" title="Your request has been sent">
          An administrator will approve and reschedule the work, ask to discuss it with you, or
          decline with an explanation. You can follow it on{' '}
          <Link to="/app/work-support" className="font-medium underline underline-offset-4">
            My requests
          </Link>
          .
        </Alert>
      )}

      <JobContext maintenance={unit.active_maintenance} />
      <MachineReference unit={unit} />
    </div>
  )
}

/**
 * The notes on the job itself — what a technician needs *while* working, as
 * opposed to what they need to confirm the machine.
 *
 * Rendered from the panel's own already-scoped records. These are the jobs that
 * granted access, never a superset: the server constrained them with the same
 * predicate that authorized this page.
 */
function JobContext({ maintenance }: { maintenance: ScannedMaintenance[] }) {
  if (maintenance.length === 0) return null

  return (
    <Surface as="section" className="p-6 sm:p-8" aria-labelledby="job-context">
      <h2 id="job-context" className="flex items-center gap-2 text-lg font-semibold text-ink">
        <ClipboardList className="size-5 text-muted" aria-hidden="true" />
        Job notes
      </h2>

      <div className="mt-5 flex flex-col gap-6">
        {maintenance.map((record) => (
          <article key={record.id} className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <h3 className="text-sm font-semibold text-ink">{record.title}</h3>
              <p className="text-xs text-muted">
                {record.status_label}
                {record.scheduled_for ? ` · due ${formatDateTime(record.scheduled_for)}` : ''}
              </p>
            </div>

            {record.diagnosis && <Note label="Diagnosis">{record.diagnosis}</Note>}
            {record.root_cause && <Note label="Root cause">{record.root_cause}</Note>}
            {record.resolution && <Note label="Recorded so far">{record.resolution}</Note>}

            {record.checklist.length > 0 && (
              <div className="flex flex-col gap-2">
                <p className="text-xs font-medium tracking-wide text-muted">Work instructions</p>
                <ul className="flex flex-col gap-1.5">
                  {record.checklist.map((item) => (
                    <li key={item.id} className="flex items-start gap-2 text-sm text-ink">
                      <span
                        className={
                          item.is_completed
                            ? 'mt-1.5 size-2 shrink-0 rounded-full bg-success-strong'
                            : 'mt-1.5 size-2 shrink-0 rounded-full border-2 border-control-border'
                        }
                        aria-hidden="true"
                      />
                      <span>
                        {item.label}
                        {item.is_required && !item.is_completed && (
                          <span className="ml-2 text-xs font-medium text-warning-strong">
                            required
                          </span>
                        )}
                        <span className="sr-only">
                          {item.is_completed ? ' — done' : ' — outstanding'}
                        </span>
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </article>
        ))}
      </div>
    </Surface>
  )
}

/** The specification, what is fitted, and any ticket this caller is working. */
function MachineReference({ unit }: { unit: ScannedPcUnit }) {
  const spec = Object.entries(unit.specification ?? {}).filter(
    ([, value]) => typeof value === 'string' && value !== '',
  )

  if (
    spec.length === 0 &&
    unit.installed_components.length === 0 &&
    unit.active_tickets.length === 0
  ) {
    return null
  }

  return (
    <Surface as="section" className="p-6 sm:p-8" aria-labelledby="machine-reference">
      <h2 id="machine-reference" className="flex items-center gap-2 text-lg font-semibold text-ink">
        <Cpu className="size-5 text-muted" aria-hidden="true" />
        This machine
      </h2>

      <div className="mt-5 flex flex-col gap-6">
        {spec.length > 0 && (
          <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
            {spec.map(([key, value]) => (
              <div key={key} className="flex flex-col gap-0.5">
                <dt className="text-xs font-medium tracking-wide text-muted">{humanise(key)}</dt>
                <dd className="text-sm text-ink">{String(value)}</dd>
              </div>
            ))}
          </dl>
        )}

        {unit.installed_components.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-xs font-medium tracking-wide text-muted">Fitted components</p>
            <ul className="flex flex-col gap-1.5">
              {unit.installed_components.map((component, index) => (
                <li key={`${component.name}-${index}`} className="text-sm text-ink">
                  {component.name ?? 'Unnamed component'}
                  {component.category_label && (
                    <span className="text-muted"> · {component.category_label}</span>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}

        {unit.active_tickets.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="flex items-center gap-2 text-xs font-medium tracking-wide text-muted">
              <Ticket className="size-3.5" aria-hidden="true" />
              Open tickets assigned to you
            </p>
            <ul className="flex flex-col gap-1.5">
              {unit.active_tickets.map((ticket) => (
                <li key={ticket.id} className="text-sm text-ink">
                  <span className="font-mono text-xs text-muted">{ticket.number}</span>{' '}
                  {ticket.title}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Surface>
  )
}

function Note({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <p className="text-xs font-medium tracking-wide text-muted">{label}</p>
      <p className="max-w-[70ch] text-sm leading-relaxed text-pretty text-ink">{children}</p>
    </div>
  )
}

function humanise(key: string): string {
  return key.replace(/_/g, ' ').replace(/^./, (character) => character.toUpperCase())
}

/**
 * Skeletons rather than a spinner: the shape of the page is known before its
 * content is, and a technician glancing at a phone should see the panel
 * arriving rather than a blank screen with a wheel on it.
 */
function PanelSkeleton() {
  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <Surface className="p-6 sm:p-8">
        <Skeleton className="h-9 w-52" />
        <Skeleton className="mt-3 h-5 w-64" />
        <Skeleton className="mt-4 h-4 w-40" />
      </Surface>
      <Surface className="p-6 sm:p-8">
        <Skeleton className="h-6 w-48" />
        <Skeleton className="mt-4 h-24 w-full" />
        <Skeleton className="mt-4 h-15 w-full max-w-56" />
      </Surface>
    </div>
  )
}
