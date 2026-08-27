import { ArrowRightLeft, Archive, Tag, X } from 'lucide-react'
import { Button } from '@/components/ui'

/**
 * The selection bar for bulk actions.
 *
 * The selection itself is real — the count is live and clearing works — but the
 * actions are **disabled placeholders**: bulk transfer, bulk status change and
 * bulk archive each need their own audited write path and a confirmation flow
 * that names what will happen to how many records, and that is a later phase.
 *
 * They are rendered rather than hidden so the shape of the feature is settled
 * now: when the endpoints land, this component gains handlers and nothing about
 * the directory layout has to move. An operator meanwhile sees what is coming
 * instead of a selection that appears to do nothing.
 */
export function BulkActionBar({ count, onClear }: { count: number; onClear: () => void }) {
  const soon = 'Bulk actions arrive in a later phase'

  return (
    <div
      className="flex flex-wrap items-center gap-4 rounded-lg border-2 border-primary bg-primary-subtle p-5"
      role="status"
      aria-live="polite"
    >
      <p className="text-base font-bold text-primary-strong">
        {count} asset{count === 1 ? '' : 's'} selected
      </p>

      <div className="ml-auto flex flex-wrap gap-3">
        <Button variant="secondary" size="sm" disabled title={soon}>
          <ArrowRightLeft size={32} aria-hidden="true" />
          Transfer
        </Button>
        <Button variant="secondary" size="sm" disabled title={soon}>
          <Tag size={32} aria-hidden="true" />
          Change status
        </Button>
        <Button variant="secondary" size="sm" disabled title={soon}>
          <Archive size={32} aria-hidden="true" />
          Archive
        </Button>
        <Button variant="ghost" size="sm" onClick={onClear}>
          <X size={32} aria-hidden="true" />
          Clear selection
        </Button>
      </div>
    </div>
  )
}
