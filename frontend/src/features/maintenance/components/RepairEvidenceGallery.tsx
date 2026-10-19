import { Camera, FileText, Trash2, Upload } from 'lucide-react'
import { type ChangeEvent, useEffect, useState } from 'react'
import { Alert, Button, ConfirmDialog, EmptyState, Modal, Select } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import { fetchEvidenceBlob } from '../api/maintenanceApi'
import { useDeleteEvidence, useUploadEvidence } from '../hooks/mutations'
import type { EvidenceItem, EvidenceStage } from '../types'

const STAGES: { value: EvidenceStage; label: string }[] = [
  { value: 'before', label: 'Before' },
  { value: 'during', label: 'During' },
  { value: 'after', label: 'After' },
]

/**
 * Repair evidence for one maintenance record (SRS FR-MNT-005/010).
 *
 * Grouped by stage rather than by upload time, because before / during / after
 * is the story the evidence tells — a flat gallery would leave a reader
 * reconstructing the order from timestamps.
 *
 * Files live on a private disk, so an `<img src>` cannot fetch one: each
 * thumbnail pulls its bytes through the authenticated API client and holds an
 * object URL, revoked on unmount so the tab does not leak blobs. That is also
 * what lets the server force `Content-Disposition: attachment` on documents
 * without breaking image previews (SDD DD-45).
 */
export function RepairEvidenceGallery({
  recordId,
  evidence,
  canManage,
  requiresEvidence,
}: {
  recordId: string
  evidence: EvidenceItem[]
  canManage: boolean
  /** Corrective work cannot be completed without at least one item. */
  requiresEvidence: boolean
}) {
  const upload = useUploadEvidence(recordId)
  const remove = useDeleteEvidence(recordId)

  const [stage, setStage] = useState<EvidenceStage>('before')
  const [error, setError] = useState<string | null>(null)
  const [lightbox, setLightbox] = useState<EvidenceItem | null>(null)
  const [deleting, setDeleting] = useState<EvidenceItem | null>(null)

  async function onPick(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = '' // allow re-picking the same file after an error
    if (!file) return

    setError(null)
    try {
      await upload.mutateAsync({ file, imageType: stage })
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  async function confirmDelete() {
    if (!deleting) return
    setError(null)
    try {
      await remove.mutateAsync(deleting.id)
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setDeleting(null)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      {requiresEvidence && evidence.length === 0 && (
        <Alert tone="warning" title="Evidence is required to complete this visit">
          Corrective work must carry at least one photograph before it can be marked complete.
        </Alert>
      )}

      {error && <Alert tone="error">{error}</Alert>}

      {canManage && (
        <div className="flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-2">
            <span className="text-sm font-semibold text-ink">Stage</span>
            <Select
              value={stage}
              onChange={(event) => setStage(event.target.value as EvidenceStage)}
            >
              {STAGES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </label>

          <label className="inline-flex">
            <input
              type="file"
              className="sr-only"
              accept="image/png,image/jpeg,image/webp,application/pdf"
              onChange={(event) => void onPick(event)}
              disabled={upload.isPending}
            />
            <span className="inline-flex h-15 cursor-pointer items-center gap-2 rounded-md border-2 border-control-border px-4 text-sm font-semibold text-ink hover:border-primary">
              <Upload className="size-4" aria-hidden="true" />
              {upload.isPending ? 'Uploading…' : 'Add evidence'}
            </span>
          </label>
        </div>
      )}

      {evidence.length === 0 ? (
        <EmptyState
          icon={<Camera className="size-6" aria-hidden="true" />}
          title="No evidence yet"
          description={
            canManage
              ? 'Photograph the fault before you start, the work in progress, and the result.'
              : 'Nothing has been attached to this visit.'
          }
        />
      ) : (
        STAGES.filter((s) => evidence.some((item) => item.image_type === s.value)).map((s) => (
          <section key={s.value} className="flex flex-col gap-3">
            <h3 className="text-sm font-semibold text-ink-strong">{s.label}</h3>
            <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {evidence
                .filter((item) => item.image_type === s.value)
                .map((item) => (
                  <li key={item.id}>
                    <EvidenceTile
                      recordId={recordId}
                      item={item}
                      canManage={canManage}
                      onOpen={() => setLightbox(item)}
                      onDelete={() => setDeleting(item)}
                    />
                  </li>
                ))}
            </ul>
          </section>
        ))
      )}

      <Modal
        open={lightbox !== null}
        onClose={() => setLightbox(null)}
        title={lightbox?.filename ?? 'Evidence'}
      >
        {lightbox && <EvidencePreview recordId={recordId} item={lightbox} large />}
      </Modal>

      <ConfirmDialog
        open={deleting !== null}
        title="Remove this evidence?"
        description="The file is deleted permanently. The audit trail keeps a record that it existed and was withdrawn."
        confirmLabel="Remove"
        tone="danger"
        loading={remove.isPending}
        onConfirm={() => void confirmDelete()}
        onClose={() => setDeleting(null)}
      />
    </div>
  )
}

function EvidenceTile({
  recordId,
  item,
  canManage,
  onOpen,
  onDelete,
}: {
  recordId: string
  item: EvidenceItem
  canManage: boolean
  onOpen: () => void
  onDelete: () => void
}) {
  return (
    <div className="group relative overflow-hidden rounded-lg border-2 border-border bg-surface">
      <button
        type="button"
        onClick={onOpen}
        className="block w-full text-left"
        aria-label={`Open ${item.filename ?? 'evidence'}`}
      >
        <EvidencePreview recordId={recordId} item={item} />
      </button>

      <div className="border-t-2 border-border p-3">
        <p className="truncate text-xs font-medium text-ink">{item.filename ?? 'Evidence'}</p>
        <p className="text-xs text-muted">
          {item.uploaded_by?.name ?? 'Unknown'} · {formatDateTime(item.created_at)}
        </p>
        {item.caption && <p className="mt-1 text-xs text-muted">{item.caption}</p>}
      </div>

      {canManage && (
        <Button
          variant="ghost"
          className="absolute right-2 top-2 opacity-0 focus-visible:opacity-100 group-hover:opacity-100"
          onClick={onDelete}
          aria-label={`Remove ${item.filename ?? 'evidence'}`}
        >
          <Trash2 className="size-4" aria-hidden="true" />
        </Button>
      )}
    </div>
  )
}

/**
 * Pull the bytes through the authenticated client and hold an object URL.
 *
 * Revoked on unmount and whenever the item changes, so a long session browsing
 * evidence does not accumulate blobs in the tab.
 */
function EvidencePreview({
  recordId,
  item,
  large = false,
}: {
  recordId: string
  item: EvidenceItem
  large?: boolean
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    if (item.kind !== 'image') return

    let revoked = false
    let objectUrl: string | null = null

    fetchEvidenceBlob(recordId, item.id)
      .then((blob) => {
        if (revoked) return
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch(() => setFailed(true))

    return () => {
      revoked = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [recordId, item.id, item.kind])

  if (item.kind !== 'image') {
    return (
      <div className="flex aspect-4/3 items-center justify-center bg-surface-sunken">
        <FileText className="size-8 text-muted" aria-hidden="true" />
      </div>
    )
  }

  if (failed) {
    return (
      <div className="flex aspect-4/3 items-center justify-center bg-surface-sunken text-xs text-muted">
        Preview unavailable
      </div>
    )
  }

  if (!url) return <div className="aspect-4/3 animate-pulse bg-surface-sunken" />

  return (
    <img
      src={url}
      alt={item.caption ?? item.filename ?? 'Repair evidence'}
      className={large ? 'max-h-[70vh] w-full object-contain' : 'aspect-4/3 w-full object-cover'}
    />
  )
}
