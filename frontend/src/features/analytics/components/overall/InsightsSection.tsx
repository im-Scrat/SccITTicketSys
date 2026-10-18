import { isAxiosError } from 'axios'
import { Sparkles } from 'lucide-react'
import { Alert, Badge, Button, LiveRegion, Skeleton } from '@/components/ui'
import type { BadgeTone } from '@/components/ui'
import { formatDateTime } from '@/lib/datetime'
import { useGenerateNarrative, useNarrativeState } from '../../hooks/narratives'
import type { NarrativeFinding, NarrativeState, OverallParams } from '../../types'

const KIND_LABELS: Record<NarrativeFinding['kind'], string> = {
  observation: 'Observation',
  possible_explanation: 'Possible explanation',
  recommendation: 'Suggested action',
}

/** The badge for where the interpretation stands — words first, colour second. */
function statusOf(state: NarrativeState): { label: string; tone: BadgeTone } {
  const n = state.narrative
  if (!state.enabled) return { label: 'Off', tone: 'neutral' }
  if (!state.configured) return { label: 'Not configured', tone: 'neutral' }
  if (!n) return { label: 'Not generated', tone: 'neutral' }
  if (n.status === 'pending') return { label: 'Generating…', tone: 'info' }
  if (n.status === 'completed')
    return state.current
      ? { label: 'Up to date', tone: 'success' }
      : { label: 'Data changed since generated', tone: 'warning' }
  if (n.status === 'insufficient') return { label: 'Not enough data', tone: 'neutral' }
  if (n.status === 'failed') return { label: 'Failed', tone: 'warning' }
  return { label: n.status_label, tone: 'neutral' }
}

/**
 * AI insights (Phase 2.10 R5; SRS FR-RPT-011; SDD DD-95).
 *
 * **Facts first, interpretation beside them, never mixed.** For each question
 * the application's own recorded facts are shown — always, even with AI off —
 * and beside them, labelled "AI interpretation (advisory)", the findings that
 * passed validation, each citing the facts it rests on (they link to the fact
 * rows). The model's confidence is its own rating, never a probability. A
 * question without enough data says why in the application's words; when no
 * question has enough, the whole section is one short list.
 */
export function InsightsSection({ params }: { params: OverallParams }) {
  const state = useNarrativeState(params)
  const generate = useGenerateNarrative(params)
  const data = state.data

  if (state.isError && !data) {
    const status = isAxiosError(state.error) ? state.error.response?.status : undefined
    return (
      <Alert
        tone="info"
        title={
          status === 403
            ? 'AI insights are not available to you'
            : 'AI insights could not be loaded'
        }
      >
        {status === 403
          ? 'They need ticket, equipment and repair access.'
          : 'Try again in a moment.'}
      </Alert>
    )
  }

  if (!data) return <Skeleton className="h-96" />

  const status = statusOf(data)
  const narrative = data.narrative
  const questions = Object.entries(data.facts.questions)
  const anySufficient = questions.some(([, q]) => q.sufficient)
  const showFindings = narrative?.status === 'completed'
  const blockedReason = !data.enabled
    ? 'AI interpretations are switched off in AI settings.'
    : !data.configured
      ? 'No AI model with a key is configured.'
      : !anySufficient
        ? 'No question has enough recorded data.'
        : null
  const generateError = isAxiosError(generate.error)
    ? generate.error.response?.status === 429
      ? 'Too many interpretations requested this hour. Try again later.'
      : 'The interpretation could not be requested.'
    : null

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-3">
        <h2 className="text-xl font-semibold text-ink-strong">AI insights</h2>
        <p className="max-w-[70ch] text-sm text-ink">
          Recorded facts come straight from the records. The AI interpretation is advisory: it may
          be wrong. Check the facts before acting. Confidence is the model's own rating, not a
          probability.
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <Badge tone={status.tone}>{status.label}</Badge>
          {narrative?.model && <span className="text-xs text-muted">Model: {narrative.model}</span>}
          {narrative?.generated_at && (
            <span className="text-xs text-muted">
              Generated {formatDateTime(narrative.generated_at)}
            </span>
          )}
          <Button
            variant="secondary"
            size="sm"
            leftIcon={<Sparkles size={16} aria-hidden="true" />}
            onClick={() => generate.mutate()}
            loading={generate.isPending}
            disabled={blockedReason !== null || narrative?.status === 'pending'}
            aria-describedby={blockedReason ? 'insights-blocked' : undefined}
          >
            {narrative?.status === 'completed' ? 'Regenerate' : 'Generate interpretation'}
          </Button>
        </div>
        {blockedReason && (
          <p id="insights-blocked" className="text-sm text-muted">
            {blockedReason}
          </p>
        )}
        {generateError && <Alert tone="warning">{generateError}</Alert>}
        {data.changed_fact_keys.length > 0 && narrative?.status === 'completed' && (
          <Alert tone="warning" title="The data has changed since this was generated">
            {data.changed_fact_keys.length}{' '}
            {data.changed_fact_keys.length === 1 ? 'fact has' : 'facts have'} changed. Regenerate
            for a reading of the current figures; the facts below are current.
          </Alert>
        )}
        {showFindings && narrative.withheld_count > 0 && (
          <p className="text-sm text-ink">
            {narrative.withheld_count} AI{' '}
            {narrative.withheld_count === 1 ? 'statement was' : 'statements were'} withheld because{' '}
            {narrative.withheld_count === 1 ? 'it did' : 'they did'} not meet the evidence rules.
          </p>
        )}
        {narrative?.status === 'failed' && (
          <Alert tone="warning" title="The interpretation could not be produced">
            Nothing was saved from it. The recorded facts below are unaffected.
          </Alert>
        )}
      </header>

      {!anySufficient ? (
        <section aria-labelledby="insights-insufficient" className="flex flex-col gap-3">
          <h3 id="insights-insufficient" className="text-base font-semibold text-ink-strong">
            Not enough recorded data to interpret
          </h3>
          <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
            {questions.map(([key, q]) => (
              <li key={key} className="flex flex-col gap-1 px-4 py-3">
                <span className="text-sm font-semibold text-ink">{q.question}</span>
                <span className="text-sm text-muted">{q.reason}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : (
        questions.map(([key, q]) => {
          const findings = showFindings ? narrative.findings.filter((f) => f.question === key) : []
          return (
            <section
              key={key}
              aria-labelledby={`insight-${key}`}
              className="grid grid-cols-1 gap-6 rounded-lg border-2 border-border bg-surface p-4 sm:p-6 lg:grid-cols-12"
            >
              <h3
                id={`insight-${key}`}
                className="text-base font-semibold text-ink-strong lg:col-span-12"
              >
                {q.question}
              </h3>
              <div className="min-w-0 lg:col-span-7">
                <h4 className="mb-2 text-sm font-semibold text-ink">Recorded facts</h4>
                {q.facts.length === 0 ? (
                  <p className="text-sm text-muted">No facts recorded for this question.</p>
                ) : (
                  <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full border-collapse text-sm">
                      <thead className="bg-surface-sunken">
                        <tr>
                          <th scope="col" className="px-3 py-2 text-left font-semibold">
                            Fact
                          </th>
                          <th scope="col" className="px-3 py-2 text-right font-semibold">
                            Value
                          </th>
                          <th scope="col" className="px-3 py-2 text-right font-semibold">
                            Records
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {q.facts.map((fact) => (
                          <tr
                            key={fact.key}
                            id={`fact-${fact.key}`}
                            className="border-t border-border target:bg-primary-subtle"
                          >
                            <td className="px-3 py-2">
                              {fact.label}
                              <span className="block text-xs text-muted">{fact.basis}</span>
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                              {typeof fact.value === 'number'
                                ? fact.value.toLocaleString('en-GB')
                                : fact.value}
                              {fact.unit && typeof fact.value === 'number' ? ` ${fact.unit}` : ''}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                              {fact.record_count}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
              <div className="min-w-0 lg:col-span-5">
                <h4 className="mb-2 flex items-center gap-2 text-sm font-semibold text-ink">
                  AI interpretation (advisory)
                  {findings.length > 0 && <Badge tone="info">AI-generated</Badge>}
                </h4>
                {!q.sufficient ? (
                  <p className="text-sm text-muted">{q.reason}</p>
                ) : findings.length === 0 ? (
                  <p className="text-sm text-muted">
                    {narrative?.status === 'pending'
                      ? 'Generating…'
                      : showFindings
                        ? 'No statement about this question met the evidence rules.'
                        : 'Not generated for these figures.'}
                  </p>
                ) : (
                  <ul className="flex flex-col gap-4">
                    {findings.map((f, i) => (
                      <li key={i} className="flex flex-col gap-1">
                        <span className="text-xs font-semibold text-muted">
                          {KIND_LABELS[f.kind]}
                        </span>
                        <p className="text-sm text-ink">{f.text}</p>
                        <p className="text-xs text-muted">
                          Confidence: {f.confidence} · Based on:{' '}
                          {f.cited_fact_keys.map((k, j) => {
                            const fact = q.facts.find((x) => x.key === k)
                            return (
                              <span key={k}>
                                {j > 0 && ', '}
                                <a
                                  href={`#fact-${k}`}
                                  className="text-primary-strong underline-offset-4 hover:underline"
                                >
                                  {fact?.label ?? k}
                                </a>
                              </span>
                            )
                          })}
                        </p>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </section>
          )
        })
      )}

      <details className="rounded-md border border-border px-4 py-3">
        <summary className="cursor-pointer text-sm font-semibold text-ink">
          What the AI is given
        </summary>
        <ul className="mt-3 flex flex-col gap-2 text-sm text-ink">
          <li>Scope: {data.facts.scope}</li>
          <li>
            Only the counts and codes listed above — no names, ticket titles or descriptions, notes
            or costs.
          </li>
          {data.facts.limitations.map((l) => (
            <li key={l}>{l}</li>
          ))}
        </ul>
      </details>

      <LiveRegion
        message={
          narrative?.status === 'completed' && data.current
            ? 'The AI interpretation is ready.'
            : narrative?.status === 'pending'
              ? 'Generating the AI interpretation.'
              : ''
        }
      />
    </div>
  )
}
