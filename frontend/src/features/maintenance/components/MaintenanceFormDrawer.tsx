import { useEffect, useState } from 'react'
import { LocationSelect } from '@/components/LocationSelect'
import { PcUnitSelect } from '@/components/PcUnitSelect'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import type { MaintenancePayload } from '../api/maintenanceApi'
import type { MaintenanceDetail, MaintenanceOptions } from '../types'

interface MaintenanceFormDrawerProps {
  open: boolean
  onClose: () => void
  options?: MaintenanceOptions
  /** Present when editing; absent when opening new work. */
  record?: MaintenanceDetail
  onSubmit: (payload: MaintenancePayload) => Promise<unknown>
  submitting: boolean
}

/**
 * Open or edit a maintenance record (SRS FR-MNT-001/002/003).
 *
 * One form for both, because corrective and preventive are the same operation
 * with a different type — `ticket_id` null and `scheduled_for` set is all
 * "preventive with no originating ticket" means at the data layer.
 *
 * **The equipment field is not the Assets module.** `PcUnitSelect` is backed by
 * the narrow `/api/lookups/pc-units` endpoint, which returns labels only and is
 * authorized by `maintenance.*` — a technician names the machine they are
 * working on without any access to the register (SDD DD-38).
 *
 * When **editing**, the target is not offered: a record is the account of work
 * on one machine, and repointing it would rewrite two service histories at once.
 * The server refuses it regardless; the form simply does not pretend otherwise.
 */
export function MaintenanceFormDrawer({
  open,
  onClose,
  options,
  record,
  onSubmit,
  submitting,
}: MaintenanceFormDrawerProps) {
  const editing = record !== undefined

  const [title, setTitle] = useState('')
  const [type, setType] = useState('')
  const [pcUnit, setPcUnit] = useState('')
  const [room, setRoom] = useState('')
  const [scheduledFor, setScheduledFor] = useState('')
  const [diagnosis, setDiagnosis] = useState('')
  const [error, setError] = useState<string | null>(null)

  // Re-seed whenever the drawer opens so a cancelled edit does not leak into
  // the next one.
  useEffect(() => {
    if (!open) return

    setError(null)
    setTitle(record?.title ?? '')
    setType(record?.type.slug ?? options?.types[0]?.value ?? '')
    setPcUnit(record?.target?.kind === 'pc_unit' ? record.target.id : '')
    setRoom('')
    setScheduledFor(record?.scheduled_for ? record.scheduled_for.slice(0, 16) : '')
    setDiagnosis(record?.diagnosis ?? '')
  }, [open, record, options])

  const selectedType = options?.types.find((option) => option.value === type)

  async function submit() {
    setError(null)

    const payload: MaintenancePayload = {
      title: title.trim(),
      type,
      scheduled_for: scheduledFor ? new Date(scheduledFor).toISOString() : null,
      diagnosis: diagnosis.trim() || null,
    }

    if (!editing) payload.pc_unit = pcUnit || null

    try {
      await onSubmit(payload)
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={editing ? 'Edit maintenance' : 'Schedule maintenance'}
      description={
        editing
          ? 'The machine this visit is about cannot be changed — cancel and open a new record instead.'
          : 'Corrective repairs and preventive rounds are both opened here.'
      }
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="primary"
            loading={submitting}
            disabled={title.trim() === '' || type === '' || (!editing && pcUnit === '')}
            onClick={() => void submit()}
          >
            {editing ? 'Save changes' : 'Open record'}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-5">
        {error && <Alert tone="error">{error}</Alert>}

        <Field label="What needs doing" required>
          <Input
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            placeholder="Replace failing power supply"
            maxLength={255}
          />
        </Field>

        <Field
          label="Type"
          required
          hint={
            selectedType?.requires_evidence
              ? 'Corrective work needs at least one photograph before it can be completed.'
              : selectedType?.has_checklist
                ? 'This type issues a checklist when the record is opened.'
                : undefined
          }
        >
          <Select value={type} onChange={(event) => setType(event.target.value)}>
            {(options?.types ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        {!editing && (
          <>
            <PcUnitSelect
              value={pcUnit}
              onChange={setPcUnit}
              label="PC unit"
              allowEmpty={false}
              hint="The machine this visit is about."
            />

            {/*
              Offered but not submitted: the record's location is its target's,
              and naming a room here would create a second, disagreeing answer.
              It stays as a way to narrow the equipment lookup.
            */}
            <LocationSelect
              value={room}
              onChange={setRoom}
              label="Filter equipment by room"
              allowEmpty
            />
          </>
        )}

        <Field label="Scheduled for" hint="Leave empty for work that starts now.">
          <Input
            type="datetime-local"
            value={scheduledFor}
            onChange={(event) => setScheduledFor(event.target.value)}
          />
        </Field>

        <Field label="Diagnosis" hint="What you believe is wrong. Can be filled in later.">
          <Textarea
            value={diagnosis}
            onChange={(event) => setDiagnosis(event.target.value)}
            rows={4}
          />
        </Field>
      </div>
    </Drawer>
  )
}
