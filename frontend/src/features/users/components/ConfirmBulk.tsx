import { useEffect, useState } from 'react'
import { ConfirmDialog, Textarea } from '@/components/ui'
import type { BulkAction } from '../types'

interface ConfirmBulkProps {
  state: { action: BulkAction; role?: string } | null
  count: number
  pending: boolean
  onClose: () => void
  onConfirm: (reason?: string) => void
}

const copy: Partial<
  Record<BulkAction, { title: string; verb: string; tone: 'danger' | 'primary'; reason: boolean }>
> = {
  suspend: { title: 'Suspend users', verb: 'Suspend', tone: 'danger', reason: true },
  deactivate: { title: 'Deactivate users', verb: 'Deactivate', tone: 'danger', reason: true },
  reject: { title: 'Reject registrations', verb: 'Reject', tone: 'danger', reason: true },
  role: { title: 'Change role', verb: 'Apply role', tone: 'primary', reason: false },
}

/** Confirmation for a consequential bulk action, with an optional audited reason. */
export function ConfirmBulk({ state, count, pending, onClose, onConfirm }: ConfirmBulkProps) {
  const [reason, setReason] = useState('')

  useEffect(() => {
    if (state) setReason('')
  }, [state])

  if (!state) return null
  const config = copy[state.action] ?? {
    title: 'Apply action',
    verb: 'Apply',
    tone: 'primary' as const,
    reason: false,
  }

  return (
    <ConfirmDialog
      open
      onClose={onClose}
      onConfirm={() => onConfirm(reason.trim() || undefined)}
      title={config.title}
      description={`This will affect ${count} selected user(s) and is recorded in the audit log.`}
      confirmLabel={config.verb}
      tone={config.tone}
      loading={pending}
    >
      {config.reason ? (
        <label className="flex flex-col gap-1.5 text-xs font-medium text-ink">
          Reason <span className="text-muted">(optional, stored in the audit trail)</span>
          <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
      ) : (
        <p className="text-sm text-muted">Confirm applying this change to the selected users.</p>
      )}
    </ConfirmDialog>
  )
}
