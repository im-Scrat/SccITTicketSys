import { Cpu } from 'lucide-react'
import { useState } from 'react'
import {
  Alert,
  Button,
  EmptyState,
  Field,
  Input,
  Modal,
  Select,
  Surface,
  Textarea,
} from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useAssetLookup } from '@/hooks/useEquipmentLookup'
import { formatDate } from '@/lib/datetime'
import { useRecordReplacement } from '../hooks/mutations'
import type { HardwareReplacement } from '../types'

/**
 * Parts swapped during this visit (SRS FR-MNT-006, AC-MNT-006).
 *
 * Recording a replacement does two things at once: it writes the row, and it
 * reconciles the machine's installation history — closing the removed unit's
 * installation and opening one for the unit fitted. That is why the list is
 * **append-only**: the history it wrote is now part of two other records'
 * timelines, so correcting a mistake means recording the reverse swap, not
 * editing away the evidence that the first one happened.
 *
 * Both serialized fields are optional, because a fan, a thermal pad or a cable
 * is a real replacement that was never a registered asset. The catalogue
 * component fields the API also accepts are not offered here: a technician holds
 * no `assets.*` permission, so there is no catalogue browser to populate them
 * from, and a control with nothing behind it would be a lie.
 */
export function HardwareReplacementPanel({
  recordId,
  replacements,
  canRecord,
}: {
  recordId: string
  replacements: HardwareReplacement[]
  canRecord: boolean
}) {
  const record = useRecordReplacement(recordId)
  const assets = useAssetLookup(undefined, canRecord)

  const [open, setOpen] = useState(false)
  const [oldAsset, setOldAsset] = useState('')
  const [newAsset, setNewAsset] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [reason, setReason] = useState('')
  const [warranty, setWarranty] = useState('')
  const [error, setError] = useState<string | null>(null)

  function reset() {
    setOldAsset('')
    setNewAsset('')
    setQuantity('1')
    setReason('')
    setWarranty('')
    setError(null)
  }

  async function submit() {
    setError(null)
    try {
      await record.mutateAsync({
        old_asset: oldAsset || null,
        new_asset: newAsset || null,
        quantity: Number(quantity) || 1,
        reason: reason.trim() || null,
        warranty_months: warranty === '' ? null : Number(warranty),
      })
      setOpen(false)
      reset()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-5">
      {canRecord && (
        <div>
          <Button
            variant="secondary"
            onClick={() => {
              reset()
              setOpen(true)
            }}
          >
            Record a replacement
          </Button>
        </div>
      )}

      {replacements.length === 0 ? (
        <EmptyState
          icon={<Cpu className="size-6" aria-hidden="true" />}
          title="No parts replaced"
          description="Recording a replacement also updates the machine's installed-component history."
        />
      ) : (
        <ol className="flex flex-col gap-3">
          {replacements.map((item) => (
            <li key={item.id}>
              <Surface className="p-4">
                <p className="text-sm font-medium text-ink-strong">
                  {item.old_asset?.label ?? item.old_component?.label ?? 'Part'}
                  <span className="text-muted"> → </span>
                  {item.new_asset?.label ?? item.new_component?.label ?? 'replacement'}
                  {item.quantity > 1 && <span className="text-muted tnum"> × {item.quantity}</span>}
                </p>

                {item.reason && <p className="mt-1 text-sm text-muted">{item.reason}</p>}

                <p className="mt-2 text-xs text-muted">
                  {formatDate(item.replaced_at)}
                  {item.warranty_months !== null && ` · ${item.warranty_months} month warranty`}
                </p>
              </Surface>
            </li>
          ))}
        </ol>
      )}

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Record a hardware replacement"
        description="The machine's installed-component history updates with this."
        footer={
          <div className="flex justify-end gap-3">
            <Button variant="ghost" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button variant="primary" loading={record.isPending} onClick={() => void submit()}>
              Record replacement
            </Button>
          </div>
        }
      >
        <div className="flex flex-col gap-5">
          {error && <Alert tone="error">{error}</Alert>}

          <Field
            label="Part removed"
            hint="Leave empty when the part was never a registered asset — a fan, a cable."
          >
            <Select value={oldAsset} onChange={(event) => setOldAsset(event.target.value)}>
              <option value="">Not a registered asset</option>
              {(assets.data ?? []).map((asset) => (
                <option key={asset.id} value={asset.id}>
                  {asset.identifier ? `${asset.identifier} — ${asset.label}` : asset.label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Part fitted" hint="Same again — optional.">
            <Select value={newAsset} onChange={(event) => setNewAsset(event.target.value)}>
              <option value="">Not a registered asset</option>
              {(assets.data ?? []).map((asset) => (
                <option key={asset.id} value={asset.id}>
                  {asset.identifier ? `${asset.identifier} — ${asset.label}` : asset.label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Quantity" required>
            <Input
              type="number"
              min={1}
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
            />
          </Field>

          <Field label="Why it was replaced">
            <Textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} />
          </Field>

          <Field label="Warranty (months)" hint="On the part that was fitted, if it carries one.">
            <Input
              type="number"
              min={0}
              value={warranty}
              onChange={(event) => setWarranty(event.target.value)}
            />
          </Field>
        </div>
      </Modal>
    </div>
  )
}
