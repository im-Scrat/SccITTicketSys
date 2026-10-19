import { isAxiosError } from 'axios'
import { Alert, Button } from '@/components/ui'
import { useConfirmImport } from '../hooks/mutations'
import { plural } from '../lib/steps'
import type { AssetImport, ImportPlan } from '../types'
import { ImportActionBar } from './ImportActionBar'
import { ImportPanel } from './ImportPanel'

/**
 * Confirm (Phase 2.10 R6, owner decision D3): exactly what saving will do, in
 * counts, and the promise that goes with it — each row saves on its own, and
 * any failures are listed for a retry. The request carries these counts; if
 * the rows changed meanwhile the server refuses it with the plan as it is now,
 * which this screen then shows.
 */
export function ConfirmStep({ item, onBack }: { item: AssetImport; onBack: () => void }) {
  const confirm = useConfirmImport(item.id)
  const plan = item.plan as ImportPlan
  const conflict = isAxiosError(confirm.error) && confirm.error.response?.status === 409
  const refused = isAxiosError(confirm.error)
    ? ((confirm.error.response?.data as { message?: string } | undefined)?.message ??
      'The import could not be started.')
    : null

  const facts: Array<[string, string]> = [
    ...(plan.pc_units > 0
      ? [['PC units to create', plan.pc_units.toLocaleString()] as [string, string]]
      : []),
    ...(plan.assets > 0
      ? [['Other equipment to create', plan.assets.toLocaleString()] as [string, string]]
      : []),
    ['Placed in', plural(plan.rooms, 'room')],
    ...(plan.without_room > 0
      ? [['Without a room, by your choice', plural(plan.without_room, 'row')] as [string, string]]
      : []),
    ...(plan.duplicates > 0
      ? [
          [
            'Duplicates to skip',
            `${plural(plan.duplicates, 'row')} — existing records stay exactly as they are`,
          ] as [string, string],
        ]
      : []),
    ...(plan.excluded > 0 ? [['Excluded', plural(plan.excluded, 'row')] as [string, string]] : []),
  ]

  return (
    <div className="flex flex-col gap-8">
      <ImportPanel
        id="import-plan"
        title="What will happen"
        description="Each row saves on its own; any failures are listed for you to fix and retry."
      >
        <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-[max-content_minmax(0,1fr)]">
          {facts.map(([term, value]) => (
            <div key={term} className="contents">
              <dt className="text-sm text-muted">{term}</dt>
              <dd className="text-base font-semibold tabular-nums text-ink-strong">{value}</dd>
            </div>
          ))}
        </dl>

        {plan.catalog_entries.length > 0 && (
          <div className="mt-6 border-t border-border pt-4">
            <h3 className="text-base font-semibold text-ink-strong">
              New catalog entries ({plan.catalog_entries.length})
            </h3>
            <ul className="mt-2 flex flex-col gap-1 text-sm text-ink">
              {plan.catalog_entries.map((entry) => (
                <li key={entry.uuid}>
                  {entry.label} <span className="text-muted">· {plural(entry.rows, 'row')}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </ImportPanel>

      {refused && (
        <Alert
          tone={conflict ? 'warning' : 'error'}
          title={conflict ? 'The rows changed' : 'Not started'}
        >
          {refused}
        </Alert>
      )}

      <ImportActionBar reason={null}>
        <Button variant="secondary" onClick={onBack}>
          Back to review
        </Button>
        <Button
          disabled={confirm.isPending || plan.total === 0}
          onClick={() =>
            confirm.mutate({
              revision: plan.revision,
              expected: {
                pc_units: plan.pc_units,
                assets: plan.assets,
                catalog_entries: plan.catalog_entries.length,
              },
            })
          }
        >
          {confirm.isPending ? 'Starting…' : `Import ${plural(plan.total, 'record')}`}
        </Button>
      </ImportActionBar>
    </div>
  )
}
