import { Download, Mail, Power, PowerOff, RotateCcw, X } from 'lucide-react'
import { useState } from 'react'
import { Button, Select } from '@/components/ui'
import type { BulkAction, RoleOption } from '../types'

interface BulkActionBarProps {
  count: number
  roles: RoleOption[]
  pending: boolean
  onClear: () => void
  onAction: (action: BulkAction, extra?: { role?: string }) => void
}

/**
 * Contextual action bar shown while directory rows are selected. Destructive
 * actions (suspend/reject) are confirmed by the parent before running; every
 * action is server-validated per record and fully audited.
 */
export function BulkActionBar({ count, roles, pending, onClear, onAction }: BulkActionBarProps) {
  const [role, setRole] = useState('')

  if (count === 0) return null

  return (
    <div className="sticky bottom-3 z-10 mt-3 flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 shadow-[var(--shadow-overlay-sm)]">
      <span className="text-sm font-medium text-ink-strong tnum">{count} selected</span>
      <span className="mx-1 h-4 w-px bg-border" aria-hidden="true" />

      <Button
        variant="secondary"
        size="sm"
        leftIcon={<Power size={15} />}
        loading={pending}
        onClick={() => onAction('activate')}
      >
        Activate
      </Button>
      <Button
        variant="secondary"
        size="sm"
        leftIcon={<PowerOff size={15} />}
        onClick={() => onAction('suspend')}
      >
        Suspend
      </Button>
      <Button
        variant="secondary"
        size="sm"
        leftIcon={<RotateCcw size={15} />}
        onClick={() => onAction('reactivate')}
      >
        Reactivate
      </Button>
      <Button
        variant="secondary"
        size="sm"
        leftIcon={<Mail size={15} />}
        onClick={() => onAction('notify')}
      >
        Notify
      </Button>
      <Button
        variant="secondary"
        size="sm"
        leftIcon={<Download size={15} />}
        onClick={() => onAction('export')}
      >
        Export
      </Button>

      <span className="flex items-center gap-1.5">
        <Select
          aria-label="Assign role to selected"
          className="h-8 w-auto"
          value={role}
          onChange={(e) => setRole(e.target.value)}
        >
          <option value="">Set role…</option>
          {roles.map((r) => (
            <option key={r.slug} value={r.slug ?? ''}>
              {r.name}
            </option>
          ))}
        </Select>
        <Button
          variant="secondary"
          size="sm"
          disabled={!role}
          onClick={() => onAction('role', { role })}
        >
          Apply
        </Button>
      </span>

      <button
        type="button"
        onClick={onClear}
        aria-label="Clear selection"
        className="ml-auto rounded-sm p-1.5 text-muted hover:bg-surface-sunken hover:text-ink"
      >
        <X size={16} aria-hidden="true" />
      </button>
    </div>
  )
}
