import { AlertTriangle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, EmptyState, Pagination, Select, Skeleton, Surface, Tabs } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDate } from '@/lib/datetime'
import { PredictionRiskBadge, PredictionStatusBadge } from '../components/PredictionBadges'
import { usePredictions } from '../hooks/queries'
import type { PredictionRiskValue, PredictionStatusValue } from '../types'

const STATUS_TABS: { value: PredictionStatusValue | 'all'; label: string }[] = [
  { value: 'pending', label: 'Needs a decision' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'dismissed', label: 'Dismissed' },
  { value: 'expired', label: 'Expired' },
  { value: 'all', label: 'All' },
]

const RISK_OPTIONS: { value: PredictionRiskValue | 'all'; label: string }[] = [
  { value: 'all', label: 'Every risk level' },
  { value: 'high', label: 'High risk' },
  { value: 'medium', label: 'Medium risk' },
  { value: 'low', label: 'Low risk' },
]

/**
 * Predictive-maintenance findings, Administrator-only (WP-M; SRS FR-AI-011).
 *
 * A finding is advisory, not a command: nothing here schedules a repair or
 * touches a PC's status by itself. Confirming or dismissing is the one thing
 * an administrator does with it — recording that the finding did or did not
 * match reality, on the detail page each card opens onto.
 */
export default function PredictionsListPage() {
  useDocumentMeta({ title: 'Predictive maintenance' })

  const [status, setStatus] = useState<PredictionStatusValue | 'all'>('pending')
  const [risk, setRisk] = useState<PredictionRiskValue | 'all'>('all')
  const [page, setPage] = useState(1)

  const query = usePredictions({
    status: status === 'all' ? undefined : status,
    risk_level: risk === 'all' ? undefined : risk,
    page,
  })

  const rows = query.data?.data ?? []
  const meta = query.data?.meta

  function changeStatus(next: string) {
    setStatus(next as PredictionStatusValue | 'all')
    setPage(1)
  }

  function changeRisk(next: string) {
    setRisk(next as PredictionRiskValue | 'all')
    setPage(1)
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          Predictive maintenance
        </h1>
        <p className="mt-1 max-w-[70ch] text-sm text-muted">
          Findings a model has surfaced from repair history. Nothing here is acted on automatically
          — every finding waits for an administrator to confirm it matched reality or dismiss it.
        </p>
      </div>

      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Tabs tabs={STATUS_TABS} value={status} onChange={changeStatus} />

        <label className="flex items-center gap-2 text-sm text-muted">
          <span className="shrink-0">Risk</span>
          <Select
            value={risk}
            onChange={(event) => changeRisk(event.target.value)}
            className="!h-10 !w-auto"
            aria-label="Filter by risk level"
          >
            {RISK_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </label>
      </div>

      {query.isError && (
        <Alert tone="error" title="Findings could not be loaded">
          Nothing has been lost — this is a display problem.
        </Alert>
      )}

      {query.isPending ? (
        <div className="flex flex-col gap-3">
          {Array.from({ length: 3 }).map((_, index) => (
            <Surface key={index} className="p-4">
              <Skeleton height={12} width="40%" />
              <Skeleton height={10} width="70%" className="mt-2" />
            </Surface>
          ))}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<AlertTriangle size={22} />}
          title={status === 'pending' ? 'Nothing waiting on you' : 'No findings here'}
          description={
            status === 'pending'
              ? 'Every predictive-maintenance finding has been decided. New ones appear here as the model detects a recurring pattern in repair history.'
              : 'No findings are in this state.'
          }
        />
      ) : (
        <ul className="flex flex-col gap-3">
          {rows.map((prediction) => (
            <li key={prediction.id}>
              <Link to={`/app/predictions/${prediction.id}`} className="block">
                <Surface className="p-4 transition-colors duration-150 hover:border-control-border">
                  <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-ink-strong">
                        {prediction.pc_unit?.label ?? 'Unknown PC'}
                        {prediction.pc_unit?.identifier && (
                          <span className="ml-2 font-normal text-muted">
                            {prediction.pc_unit.identifier}
                          </span>
                        )}
                      </p>
                      <p className="mt-1 text-sm text-ink">{prediction.predicted_issue}</p>
                    </div>

                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                      <PredictionRiskBadge risk={prediction.risk_level} />
                      <PredictionStatusBadge status={prediction.status} />
                    </div>
                  </div>

                  <p className="mt-2 text-xs text-muted">
                    Generated {formatDate(prediction.generated_at)}
                    {prediction.confidence !== null &&
                      ` · ${Math.round(prediction.confidence * 100)}% confidence`}
                  </p>
                </Surface>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {meta && rows.length > 0 && (
        <Surface className="overflow-hidden">
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            from={meta.from}
            to={meta.to}
            onPage={setPage}
          />
        </Surface>
      )}
    </div>
  )
}
