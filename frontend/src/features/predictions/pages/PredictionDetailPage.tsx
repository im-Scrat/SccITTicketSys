import { ArrowLeft, CheckCircle2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Alert, Button, ConfirmDialog, Skeleton, Surface } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDateTime } from '@/lib/datetime'
import { PredictionRiskBadge, PredictionStatusBadge } from '../components/PredictionBadges'
import { useConfirmPrediction, useDismissPrediction } from '../hooks/mutations'
import { usePrediction } from '../hooks/queries'
import type {
  EvidenceComponentReplaced,
  EvidencePattern,
  PredictionHistory,
  PredictionHistoryRepair,
} from '../types'

type Decision = 'confirm' | 'dismiss'

/**
 * One predictive-maintenance finding, in full (WP-M; SRS FR-AI-011).
 *
 * ── Confirm / dismiss are terminal ──────────────────────────────────────────
 *
 * The server refuses a second decision on an already-decided finding
 * (`PcPredictionDecision`), so both buttons are confirmed here — the same
 * standard this codebase holds every one-way decision to (compare the support
 * request inbox's decline dialog). There is no "undo"; recording a judgement
 * on a piece of evidence is not something to walk back with a second click.
 *
 * ── Evidence stays structured, never prose ──────────────────────────────────
 *
 * `explanation` and `recommendation` are the model's own words and are shown
 * as narrative text. Everything under "Evidence" — the observed counts, the
 * detected pattern, the components replaced — comes from the deterministic
 * detector, not the model, and is rendered as discrete facts so an
 * administrator can check the finding against the record it was built from.
 */
export default function PredictionDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const prediction = usePrediction(id)
  const confirm = useConfirmPrediction(id ?? '')
  const dismiss = useDismissPrediction(id ?? '')

  const [decision, setDecision] = useState<Decision | null>(null)
  const [error, setError] = useState<string | null>(null)

  useDocumentMeta({ title: prediction.data?.data.predicted_issue ?? 'Predictive maintenance' })

  async function decide() {
    if (!decision) return
    setError(null)
    try {
      await (decision === 'confirm' ? confirm.mutateAsync() : dismiss.mutateAsync())
      setDecision(null)
    } catch (caught) {
      setError(getErrorMessage(caught))
      setDecision(null)
    }
  }

  if (prediction.isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-label="Loading finding">
        <Skeleton className="h-10 w-64 rounded-md" />
        <Skeleton className="h-40 rounded-lg" />
        <Skeleton className="h-64 rounded-lg" />
      </div>
    )
  }

  if (prediction.isError || !prediction.data) {
    return (
      <div className="flex flex-col gap-6">
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/predictions')}>
          <ArrowLeft size={20} aria-hidden="true" />
          Predictive maintenance
        </Button>

        <Alert tone="error" title="This finding is not available">
          It may have been superseded by a newer assessment, or you may not have permission to view
          it.
        </Alert>
      </div>
    )
  }

  const data = prediction.data.data
  const canDecide = data.can.decide && data.status.value === 'pending'

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/predictions')}>
          <ArrowLeft size={20} aria-hidden="true" />
          Predictive maintenance
        </Button>
      </div>

      {error && (
        <Alert tone="error" title="That decision was not recorded">
          {error}
        </Alert>
      )}

      <header className="flex flex-col gap-4">
        <div className="flex flex-wrap items-start justify-between gap-6">
          <div className="measure">
            <h1 className="text-2xl font-bold text-ink-strong">{data.predicted_issue}</h1>
            <p className="mt-1 text-sm text-muted">
              {data.pc_unit?.label ?? 'Unknown PC'}
              {data.pc_unit?.identifier && ` · ${data.pc_unit.identifier}`}
              {data.pc_unit?.asset_tag && ` · ${data.pc_unit.asset_tag}`}
            </p>
            <p className="mt-1 text-sm text-muted">
              {[data.location?.room, data.location?.floor, data.location?.building]
                .filter(Boolean)
                .join(' · ') || 'Location unknown'}
            </p>

            <div className="mt-3 flex flex-wrap items-center gap-2">
              <PredictionRiskBadge risk={data.risk_level} />
              <PredictionStatusBadge status={data.status} />
            </div>
          </div>

          {canDecide && (
            <div className="flex shrink-0 flex-wrap gap-2">
              <Button
                variant="primary"
                leftIcon={<CheckCircle2 className="size-4" aria-hidden="true" />}
                onClick={() => setDecision('confirm')}
              >
                Confirm finding
              </Button>
              <Button
                variant="secondary"
                leftIcon={<XCircle className="size-4" aria-hidden="true" />}
                onClick={() => setDecision('dismiss')}
              >
                Dismiss
              </Button>
            </div>
          )}
        </div>

        {!canDecide && data.status.value !== 'pending' && (
          <Alert tone="info" title={`This finding was ${data.status.label.toLowerCase()}`}>
            Decisions on a predictive-maintenance finding are final.
          </Alert>
        )}
      </header>

      <Surface as="dl" className="grid gap-6 p-6 sm:grid-cols-2 lg:grid-cols-4">
        <Fact label="Confidence" value={formatPercent(data.confidence)} />
        <Fact
          label="Probability"
          value={data.probability !== null ? formatPercent(data.probability) : '—'}
          hint={data.probability === null ? 'No calibrated failure model exists yet.' : undefined}
        />
        <Fact
          label="Predicted window"
          value={
            data.predicted_within_days !== null
              ? `${data.predicted_within_days} days`
              : 'Not stated'
          }
          hint={data.evidence.time_window.basis}
        />
        <Fact
          label="AI model"
          value={data.ai_model ? `${data.ai_model.provider} · ${data.ai_model.model}` : '—'}
        />
        <Fact
          label="Failure pattern"
          value={data.failure_pattern?.name ?? '—'}
          hint={
            data.failure_pattern
              ? `${data.failure_pattern.occurrence_count} occurrences`
              : undefined
          }
        />
        <Fact
          label="Generated"
          value={data.generated_at ? formatDateTime(data.generated_at) : '—'}
        />
      </Surface>

      <div className="flex flex-col gap-6">
        <Narrative label="Explanation" value={data.explanation} />
        <Narrative label="Recommendation" value={data.recommendation} />

        <section>
          <h2 className="text-sm font-semibold text-ink-strong">Evidence</h2>
          <p className="mt-1 text-xs text-muted">
            The observed facts and detected pattern this finding was generated from — never the
            model's own words.
          </p>

          <Surface as="dl" className="mt-3 grid gap-6 p-6 sm:grid-cols-2 lg:grid-cols-4">
            <Fact
              label="Completed repairs"
              value={String(data.evidence.observed.completed_repairs ?? 0)}
            />
            <Fact
              label="Corrective repairs"
              value={String(data.evidence.observed.corrective_repairs ?? 0)}
            />
            <Fact
              label="Preventive visits"
              value={String(data.evidence.observed.preventive_visits ?? 0)}
            />
            <Fact
              label="Last repair"
              value={
                data.evidence.observed.last_completed_at
                  ? formatDateTime(data.evidence.observed.last_completed_at)
                  : '—'
              }
            />
          </Surface>

          {data.evidence.observed.components_replaced &&
            data.evidence.observed.components_replaced.length > 0 && (
              <div className="mt-4">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted">
                  Components replaced
                </h3>
                <ul className="mt-2 flex flex-col gap-1.5">
                  {data.evidence.observed.components_replaced.map((component) => (
                    <ComponentRow key={component.component_type} component={component} />
                  ))}
                </ul>
              </div>
            )}

          {data.evidence.patterns.length > 0 && (
            <div className="mt-4 flex flex-col gap-3">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-muted">
                Detected pattern{data.evidence.patterns.length > 1 ? 's' : ''}
              </h3>
              {data.evidence.patterns.map((pattern, index) => (
                <PatternCard key={`${pattern.name}-${index}`} pattern={pattern} />
              ))}
            </div>
          )}
        </section>

        {data.history && <RepairHistory history={data.history} />}
      </div>

      <ConfirmDialog
        open={decision !== null}
        onClose={() => setDecision(null)}
        onConfirm={() => void decide()}
        title={decision === 'confirm' ? 'Confirm this finding?' : 'Dismiss this finding?'}
        description={
          decision === 'confirm'
            ? "This records that the finding matched what you observed. It does not schedule a repair or change the machine's status — that stays a separate step."
            : 'This records that the finding did not match what you observed. Decisions here are final and cannot be reopened.'
        }
        confirmLabel={decision === 'confirm' ? 'Confirm finding' : 'Dismiss finding'}
        tone={decision === 'dismiss' ? 'danger' : 'primary'}
        loading={confirm.isPending || dismiss.isPending}
      />
    </div>
  )
}

function formatPercent(value: number | null): string {
  if (value === null) return '—'
  return `${Math.round(value * 100)}%`
}

/*
 * A term and its description, valid inside a `<dl>`. The hint lives *inside* the
 * `<dd>`: a `<div>` grouping a term with its description may contain only `<dt>`
 * and `<dd>`, so a sibling `<p>` makes the list invalid — which axe reports as a
 * serious `definition-list` violation.
 */
function Fact({ label, value, hint }: { label: string; value: string; hint?: string | null }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{label}</dt>
      <dd className="mt-1">
        <span className="block text-sm font-medium text-ink-strong">{value}</span>
        {hint && <span className="block text-xs text-muted">{hint}</span>}
      </dd>
    </div>
  )
}

function Narrative({ label, value }: { label: string; value: string | null }) {
  return (
    <section>
      <h2 className="text-sm font-semibold text-ink-strong">{label}</h2>
      {value ? (
        <p className="mt-2 whitespace-pre-wrap text-sm text-ink">{value}</p>
      ) : (
        <p className="mt-2 text-sm text-muted">Not recorded.</p>
      )}
    </section>
  )
}

function ComponentRow({ component }: { component: EvidenceComponentReplaced }) {
  return (
    <li className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2 text-sm">
      <span className="text-ink">{component.label}</span>
      <span className="tnum text-muted">
        {component.count} time{component.count === 1 ? '' : 's'}
      </span>
    </li>
  )
}

function PatternCard({ pattern }: { pattern: EvidencePattern }) {
  return (
    <Surface className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-ink-strong">{pattern.name}</p>
          <p className="mt-1 text-sm text-muted">{pattern.detected_problem}</p>
        </div>
        <span className="shrink-0 rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs font-medium text-muted tnum">
          {pattern.occurrence_count} occurrences
        </span>
      </div>

      <p className="mt-3 text-xs text-muted">
        {pattern.first_at && pattern.last_at
          ? `${formatDateTime(pattern.first_at)} — ${formatDateTime(pattern.last_at)}`
          : null}
        {pattern.average_days_between !== null &&
          ` · averaging ${pattern.average_days_between} days apart`}
      </p>
    </Surface>
  )
}

/**
 * The machine's repair history as it stands today — a different thing from the
 * evidence above it, and labelled as one.
 *
 * The evidence is what the finding *was generated from* and never changes; this
 * is what an administrator needs to see before deciding whether the finding
 * still matters, including a repair completed after it was filed. Every field
 * is structural (type, date, ticket category, parts replaced): what a
 * technician wrote about a repair stays on the maintenance record.
 */
function RepairHistory({ history }: { history: PredictionHistory }) {
  return (
    <section aria-labelledby="repair-history-heading">
      <h2 id="repair-history-heading" className="text-sm font-semibold text-ink-strong">
        Repair history now
      </h2>
      <p className="mt-1 text-xs text-muted">
        The machine as it stands today. The evidence above is what this finding was generated from
        and does not change; this may include repairs completed since.
      </p>

      <Surface as="dl" className="mt-3 grid gap-6 p-6 sm:grid-cols-3">
        <Fact
          label="Completed repairs"
          value={String(history.completed_repairs)}
          hint="All types"
        />
        <Fact
          label="Corrective repairs"
          value={String(history.corrective_repairs)}
          hint="Repairs, not preventive care"
        />
        <Fact
          label="Most recent repair"
          value={
            history.recent_repair
              ? formatDateTime(history.recent_repair.completed_at)
              : 'None recorded'
          }
          hint={history.recent_repair ? describeRepair(history.recent_repair) : undefined}
        />
      </Surface>

      <div className="mt-4">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted">
          Previous problems
        </h3>
        {history.previous_problems.length === 0 ? (
          <p className="mt-2 text-sm text-muted">No earlier repairs are recorded.</p>
        ) : (
          <ul className="mt-2 flex flex-col gap-1.5">
            {history.previous_problems.map((repair) => (
              <li
                key={repair.id}
                className="flex flex-wrap items-center justify-between gap-x-3 rounded-md border border-border px-3 py-2 text-sm"
              >
                <span className="text-ink">{describeRepair(repair)}</span>
                <span className="tnum text-muted">{formatDateTime(repair.completed_at)}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

/** "Corrective Repair · Hardware · Power Supply" — whatever of it is known. */
function describeRepair(repair: PredictionHistoryRepair): string {
  return [repair.type, repair.category, repair.components.join(', ') || null]
    .filter(Boolean)
    .join(' · ')
}
