import { Copy, Flag, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { Alert, Button, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useAssignTicket, useChangePriority, useMarkDuplicate } from '../hooks/mutations'
import { useTicketDirectory } from '../hooks/queries'
import type { TicketDetail, TicketOptions } from '../types'

interface AdminTicketActionsProps {
  ticket: TicketDetail
  options?: TicketOptions
  /** Held by administrators, and by a deputized technician (FR-ASN-001). */
  canAssign: boolean
}

/**
 * The three writes only an administrator makes: assignment, priority, and the
 * duplicate link (SRS FR-ASN-001/002, FR-TKT-004/011).
 *
 * Each is a **separate audited endpoint** rather than a field on the edit form.
 * `UpdateTicketRequest` prohibits all three outright — it does not accept and
 * discard them — so a caller can never believe a priority change was applied
 * when it was not, and every one of these carries its own reason into the audit
 * log.
 */
export function AdminTicketActions({ ticket, options, canAssign }: AdminTicketActionsProps) {
  const [open, setOpen] = useState<'assign' | 'priority' | 'duplicate' | null>(null)

  return (
    <>
      <div className="flex flex-wrap gap-3">
        {canAssign && (
          <Button variant="secondary" onClick={() => setOpen('assign')}>
            <UserPlus size={32} aria-hidden="true" />
            {ticket.is_assigned ? 'Reassign' : 'Assign a technician'}
          </Button>
        )}
        <Button variant="secondary" onClick={() => setOpen('priority')}>
          <Flag size={32} aria-hidden="true" />
          Change priority
        </Button>
        <Button variant="secondary" onClick={() => setOpen('duplicate')}>
          <Copy size={32} aria-hidden="true" />
          {ticket.duplicate_of ? 'Change duplicate link' : 'Mark as duplicate'}
        </Button>
      </div>

      <AssignDialog
        open={open === 'assign'}
        onClose={() => setOpen(null)}
        ticket={ticket}
        options={options}
      />
      <PriorityDialog
        open={open === 'priority'}
        onClose={() => setOpen(null)}
        ticket={ticket}
        options={options}
      />
      <DuplicateDialog open={open === 'duplicate'} onClose={() => setOpen(null)} ticket={ticket} />
    </>
  )
}

/* ------------------------------------------------------------------ assign */

function AssignDialog({
  open,
  onClose,
  ticket,
  options,
}: {
  open: boolean
  onClose: () => void
  ticket: TicketDetail
  options?: TicketOptions
}) {
  const assign = useAssignTicket(ticket.id)
  const [technician, setTechnician] = useState('')
  const [remarks, setRemarks] = useState('')
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    if (!technician) {
      setError('Choose who should take this.')
      return
    }
    setError(null)
    try {
      await assign.mutateAsync({ technician, remarks: remarks.trim() || undefined })
      setTechnician('')
      setRemarks('')
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={ticket.is_assigned ? 'Reassign this ticket' : 'Assign this ticket'}
      description={
        ticket.is_assigned
          ? 'The current assignment is closed and a new one is opened. Both are recorded.'
          : 'The technician is notified and can accept or decline.'
      }
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={assign.isPending}>
            Cancel
          </Button>
          <Button size="sm" loading={assign.isPending} onClick={submit}>
            {ticket.is_assigned ? 'Reassign' : 'Assign'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-5">
        {error && <Alert tone="error">{error}</Alert>}

        <Field
          label="Technician"
          required
          hint="Only active technicians and administrators can be assigned work."
        >
          <Select value={technician} onChange={(event) => setTechnician(event.target.value)}>
            <option value="">Choose a technician…</option>
            {(options?.technicians ?? []).map((person) => (
              <option key={person.value} value={person.value}>
                {person.label} — {person.role}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Remark (optional)" hint="Context for whoever picks this up.">
          <Textarea
            value={remarks}
            rows={3}
            maxLength={2000}
            onChange={(event) => setRemarks(event.target.value)}
          />
        </Field>
      </div>
    </Modal>
  )
}

/* ---------------------------------------------------------------- priority */

function PriorityDialog({
  open,
  onClose,
  ticket,
  options,
}: {
  open: boolean
  onClose: () => void
  ticket: TicketDetail
  options?: TicketOptions
}) {
  const change = useChangePriority(ticket.id)
  const [priority, setPriority] = useState(ticket.priority.slug ?? '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    if (!priority) {
      setError('Choose a priority.')
      return
    }
    setError(null)
    try {
      await change.mutateAsync({ priority, reason: reason.trim() || undefined })
      setReason('')
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Change the priority"
      description="The response and resolution deadlines are recalculated from when the fault was reported, not from now."
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={change.isPending}>
            Cancel
          </Button>
          <Button size="sm" loading={change.isPending} onClick={submit}>
            Set priority
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-5">
        {error && <Alert tone="error">{error}</Alert>}

        <Field label="Priority" required>
          <Select value={priority} onChange={(event) => setPriority(event.target.value)}>
            <option value="">Choose a priority…</option>
            {(options?.priorities ?? []).map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
                {item.resolution_minutes !== null
                  ? ` — resolve within ${Math.round(item.resolution_minutes / 60)}h`
                  : ''}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Reason (optional)" hint="Why this ticket deserves a different clock.">
          <Textarea
            value={reason}
            rows={3}
            maxLength={2000}
            onChange={(event) => setReason(event.target.value)}
          />
        </Field>
      </div>
    </Modal>
  )
}

/* --------------------------------------------------------------- duplicate */

function DuplicateDialog({
  open,
  onClose,
  ticket,
}: {
  open: boolean
  onClose: () => void
  ticket: TicketDetail
}) {
  const mark = useMarkDuplicate(ticket.id)
  const [search, setSearch] = useState('')
  const [target, setTarget] = useState(ticket.duplicate_of?.id ?? '')
  const [error, setError] = useState<string | null>(null)

  // Only searched once there is something to search on — an unfiltered
  // directory of every ticket is not a picker.
  const candidates = useTicketDirectory(
    { search: search.trim(), per_page: 20, sort: 'created_at', direction: 'desc' },
    open && search.trim().length >= 3,
  )

  const submit = async () => {
    setError(null)
    try {
      await mark.mutateAsync(target || null)
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Link this to the ticket it duplicates"
      description="The reporter still sees their own ticket and its progress; the work is tracked in one place."
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={mark.isPending}>
            Cancel
          </Button>
          <Button size="sm" loading={mark.isPending} onClick={submit}>
            {target ? 'Link as duplicate' : 'Remove the link'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-5">
        {error && <Alert tone="error">{error}</Alert>}

        <Field label="Find the original" hint="Search by title, reference or description.">
          <Input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="e.g. printer lab 3"
          />
        </Field>

        <Field label="Original ticket">
          <Select value={target} onChange={(event) => setTarget(event.target.value)}>
            <option value="">Not a duplicate</option>
            {ticket.duplicate_of && (
              <option value={ticket.duplicate_of.id}>
                {ticket.duplicate_of.ticket_number} — {ticket.duplicate_of.title}
              </option>
            )}
            {(candidates.data?.data ?? [])
              .filter((row) => row.id !== ticket.id && row.id !== ticket.duplicate_of?.id)
              .map((row) => (
                <option key={row.id} value={row.id}>
                  {row.ticket_number} — {row.title}
                </option>
              ))}
          </Select>
        </Field>

        {search.trim().length > 0 && search.trim().length < 3 && (
          <p className="text-sm text-muted">Type at least 3 characters to search.</p>
        )}
      </div>
    </Modal>
  )
}
