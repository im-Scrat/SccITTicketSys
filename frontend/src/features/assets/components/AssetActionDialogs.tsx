import { useState } from 'react'
import { Alert, Button, Field, Modal, Select, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useRoomLookup } from '@/hooks/useLocationLookup'
import { useAssignTechnician, useChangeAssetStatus, useTransferAsset } from '../hooks/mutations'
import { useAssetCatalog } from '../hooks/queries'
import type { AssetDetail, AssetStatusValue, StatusTransition } from '../types'

/**
 * Change an asset's lifecycle status (SRS FR-AST-005).
 *
 * The dropdown offers **only the transitions the server says are legal** —
 * `meta.transitions` comes back with the asset — so an operator is never invited
 * to make a move that will be refused. Terminal states are marked in the label
 * because retiring or disposing of equipment is not an ordinary status change,
 * and it additionally requires the `assets.dispose` permission.
 */
export function ChangeStatusDialog({
  open,
  onClose,
  asset,
  transitions,
}: {
  open: boolean
  onClose: () => void
  asset: AssetDetail
  transitions: StatusTransition[]
}) {
  const [status, setStatus] = useState<AssetStatusValue | ''>('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const change = useChangeAssetStatus(asset.id)

  const submit = async () => {
    if (!status) return
    setError(null)
    try {
      await change.mutateAsync({ status, reason: reason.trim() || undefined })
      setStatus('')
      setReason('')
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Change status">
      <div className="flex flex-col gap-6">
        {error && <Alert tone="error">{error}</Alert>}

        <p className="text-base text-muted measure">
          Currently <strong className="text-ink">{asset.status_label}</strong>. Every change is
          recorded in this asset’s history with your name and the reason you give.
        </p>

        {transitions.length === 0 ? (
          <Alert tone="info">
            {asset.status_label} is a final state — this asset can no longer change status.
          </Alert>
        ) : (
          <>
            <Field label="New status">
              <Select
                value={status}
                onChange={(event) => setStatus(event.target.value as AssetStatusValue)}
              >
                <option value="">Choose a status…</option>
                {transitions.map((transition) => (
                  <option key={transition.value} value={transition.value}>
                    {transition.label}
                    {transition.terminal ? ' (final)' : ''}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Reason" hint="Optional, but it is what makes the history readable later.">
              <Textarea
                rows={3}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Sent to the workshop after a power failure."
              />
            </Field>

            <div className="flex flex-wrap justify-end gap-3">
              <Button variant="secondary" onClick={onClose}>
                Cancel
              </Button>
              <Button onClick={submit} disabled={!status || change.isPending}>
                Change status
              </Button>
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}

/**
 * Move an asset between rooms (SRS FR-AST-006).
 *
 * "No room" is an offered choice, not an omission: equipment genuinely goes to
 * holding areas that are not modelled as rooms, and the transfer ledger records
 * that honestly rather than pretending the asset never moved.
 */
export function TransferAssetDialog({
  open,
  onClose,
  asset,
}: {
  open: boolean
  onClose: () => void
  asset: AssetDetail
}) {
  const [room, setRoom] = useState<string>('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const rooms = useRoomLookup(undefined, open)
  const transfer = useTransferAsset(asset.id)

  const submit = async () => {
    setError(null)
    try {
      await transfer.mutateAsync({
        room: room === '__none__' ? null : room || null,
        reason: reason.trim() || undefined,
      })
      setRoom('')
      setReason('')
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Transfer asset">
      <div className="flex flex-col gap-6">
        {error && <Alert tone="error">{error}</Alert>}

        <p className="text-base text-muted measure">
          Currently in{' '}
          <strong className="text-ink">{asset.room ? asset.room.name : 'no room'}</strong>. The move
          is recorded in the transfer history.
        </p>

        <Field label="Move to">
          <Select value={room} onChange={(event) => setRoom(event.target.value)}>
            <option value="">Choose a room…</option>
            <option value="__none__">No room (holding area)</option>
            {(rooms.data ?? []).map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Reason">
          <Textarea
            rows={3}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Deployed to the new lab."
          />
        </Field>

        <div className="flex flex-wrap justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={submit} disabled={!room || transfer.isPending}>
            Transfer
          </Button>
        </div>
      </div>
    </Modal>
  )
}

/**
 * Hand an asset to a custodian, or take it back (SRS FR-AST-002).
 *
 * Only technicians and administrators are offered — the server enforces the same
 * restriction, so a stale list cannot book equipment out to a teacher.
 */
export function AssignTechnicianDialog({
  open,
  onClose,
  asset,
}: {
  open: boolean
  onClose: () => void
  asset: AssetDetail
}) {
  const [technician, setTechnician] = useState<string>(asset.technician?.id ?? '')
  const [error, setError] = useState<string | null>(null)

  const catalog = useAssetCatalog(open)
  const assign = useAssignTechnician(asset.id)

  const submit = async () => {
    setError(null)
    try {
      await assign.mutateAsync(technician === '__none__' ? null : technician || null)
      onClose()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Assign custodian">
      <div className="flex flex-col gap-6">
        {error && <Alert tone="error">{error}</Alert>}

        <p className="text-base text-muted measure">
          Custodianship records who is responsible for this equipment. It does not change the
          asset’s lifecycle status.
        </p>

        <Field label="Custodian">
          <Select value={technician} onChange={(event) => setTechnician(event.target.value)}>
            <option value="">Choose a person…</option>
            <option value="__none__">Unassigned</option>
            {(catalog.data?.technicians ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label} ({option.role})
              </option>
            ))}
          </Select>
        </Field>

        <div className="flex flex-wrap justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={submit} disabled={!technician || assign.isPending}>
            Save
          </Button>
        </div>
      </div>
    </Modal>
  )
}
