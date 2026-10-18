import { useState } from 'react'
import { Button, ConfirmDialog, ProgressBar } from '@/components/ui'
import { useCancelImport } from '../hooks/mutations'
import { plural } from '../lib/steps'
import type { AssetImport } from '../types'
import { ImportPanel } from './ImportPanel'

/**
 * Importing (Phase 2.10 R6): progress in numbers as well as a bar, the
 * reassurance that the save continues without this page, and a way to stop
 * it — after the row it is on, keeping what was created.
 */
export function ImportProgressPanel({ item }: { item: AssetImport }) {
  const cancel = useCancelImport(item.id)
  const [asking, setAsking] = useState(false)
  const progress = item.progress
  const total = progress?.total ?? item.plan?.total ?? 0
  const done = progress?.done ?? 0

  return (
    <ImportPanel
      id="import-progress"
      title={
        item.status === 'queued'
          ? 'Waiting to start'
          : item.stop_requested
            ? 'Stopping…'
            : 'Importing'
      }
      description="You can leave this page; the import continues."
    >
      <div className="flex flex-col gap-6">
        <ProgressBar
          label="Rows saved"
          value={item.status === 'queued' ? undefined : done}
          max={Math.max(total, 1)}
          valueText={`${done.toLocaleString()} of ${total.toLocaleString()}`}
        />
        <p className="text-sm text-ink">
          {plural(progress?.created ?? 0, 'record')} created so far.
          {item.stop_requested ? ' The import stops after the row it is on.' : ''}
        </p>
        {item.can.stop && (
          <div>
            <Button variant="secondary" onClick={() => setAsking(true)}>
              Stop import
            </Button>
          </div>
        )}
      </div>
      <ConfirmDialog
        open={asking}
        onClose={() => setAsking(false)}
        onConfirm={() => cancel.mutate(undefined, { onSettled: () => setAsking(false) })}
        title="Stop this import?"
        description="It stops after the row it is on. Records already created stay; the rest are listed as not attempted, and you can retry them."
        confirmLabel="Stop import"
        loading={cancel.isPending}
      />
    </ImportPanel>
  )
}
