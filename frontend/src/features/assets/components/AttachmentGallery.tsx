import { FileText, Trash2, Upload, X } from 'lucide-react'
import { type ChangeEvent, useEffect, useState } from 'react'
import { Alert, Button, ConfirmDialog, EmptyState, Modal, Skeleton } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import { type AssetKind, fetchAttachmentObjectUrl } from '../api/assetsApi'
import { useDeleteAttachment, useUploadAttachment } from '../hooks/mutations'
import { useAttachments } from '../hooks/queries'
import type { AssetAttachment } from '../types'

/**
 * The image and document gallery for one record.
 *
 * A **multi-image grid**, not a single preview — equipment evidence is
 * inherently plural (front, rear, serial plate, damage, receipt), and a
 * one-slot preview would force people to choose which photo matters.
 *
 * Files live on a private disk, so an `<img src>` cannot fetch one directly:
 * each thumbnail pulls its bytes through the authenticated API client and holds
 * an object URL, revoked on unmount so the tab does not leak blobs.
 */
export function AttachmentGallery({
  kind,
  id,
  canManage,
}: {
  kind: AssetKind
  id: string
  canManage: boolean
}) {
  const { data, isLoading } = useAttachments(kind, id)
  const upload = useUploadAttachment(kind, id)
  const remove = useDeleteAttachment()

  const [error, setError] = useState<string | null>(null)
  const [lightbox, setLightbox] = useState<AssetAttachment | null>(null)
  const [deleting, setDeleting] = useState<AssetAttachment | null>(null)

  const onPick = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = '' // allow re-picking the same file after an error
    if (!file) return

    setError(null)
    try {
      await upload.mutateAsync({ file })
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const confirmDelete = async () => {
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

  if (isLoading) return <Skeleton className="h-64 rounded-lg" />

  const images = (data ?? []).filter((item) => item.is_image)
  const documents = (data ?? []).filter((item) => !item.is_image)

  return (
    <div className="flex flex-col gap-8">
      {error && <Alert tone="error">{error}</Alert>}

      {canManage && (
        <div>
          <label className="inline-flex cursor-pointer items-center gap-3 rounded-md border border-control-border bg-surface px-6 text-sm font-semibold text-ink shadow-sm hover:bg-surface-sunken h-15">
            <Upload size={32} aria-hidden="true" />
            {upload.isPending ? 'Uploading…' : 'Add image or document'}
            <input
              type="file"
              className="sr-only"
              accept="image/png,image/jpeg,image/webp,application/pdf"
              onChange={onPick}
              disabled={upload.isPending}
            />
          </label>
          <p className="mt-2 text-sm text-muted">PNG, JPEG or WebP images, or a PDF document.</p>
        </div>
      )}

      {(data ?? []).length === 0 ? (
        <EmptyState
          title="No images or documents yet"
          description="Photographs of the equipment, receipts and warranty paperwork belong here."
        />
      ) : (
        <>
          {images.length > 0 && (
            <section>
              <h3 className="text-base font-bold text-ink-strong">Images</h3>
              <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                {images.map((image) => (
                  <li key={image.id} className="overflow-hidden rounded-lg border-2 border-border">
                    <button
                      type="button"
                      onClick={() => setLightbox(image)}
                      className="block w-full"
                      aria-label={`View ${image.filename}`}
                    >
                      <PrivateImage attachment={image} className="h-48 w-full object-cover" />
                    </button>
                    <div className="flex items-start justify-between gap-2 p-4">
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium text-ink">
                          {image.caption ?? image.filename}
                        </span>
                        <span className="block text-xs text-muted">
                          {formatDateTime(image.uploaded_at)}
                        </span>
                      </span>
                      {canManage && (
                        <button
                          type="button"
                          onClick={() => setDeleting(image)}
                          className="flex size-12 shrink-0 items-center justify-center rounded-md text-danger-strong hover:bg-danger-subtle"
                          aria-label={`Remove ${image.filename}`}
                        >
                          <Trash2 size={26} aria-hidden="true" />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {documents.length > 0 && (
            <section>
              <h3 className="text-base font-bold text-ink-strong">Documents</h3>
              <ul className="mt-4 flex flex-col divide-y divide-border">
                {documents.map((document) => (
                  <li
                    key={document.id}
                    className="flex flex-wrap items-center justify-between gap-4 py-4"
                  >
                    <span className="flex min-w-0 items-center gap-3">
                      <FileText size={32} className="shrink-0 text-muted" aria-hidden="true" />
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium text-ink">
                          {document.caption ?? document.filename}
                        </span>
                        <span className="block text-xs text-muted">
                          {formatDateTime(document.uploaded_at)}
                        </span>
                      </span>
                    </span>
                    <span className="flex items-center gap-2">
                      <DownloadButton attachment={document} />
                      {canManage && (
                        <button
                          type="button"
                          onClick={() => setDeleting(document)}
                          className="flex size-12 items-center justify-center rounded-md text-danger-strong hover:bg-danger-subtle"
                          aria-label={`Remove ${document.filename}`}
                        >
                          <Trash2 size={26} aria-hidden="true" />
                        </button>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </>
      )}

      <Modal
        open={lightbox !== null}
        onClose={() => setLightbox(null)}
        title={lightbox?.caption ?? lightbox?.filename ?? 'Image'}
      >
        {lightbox && (
          <div className="flex flex-col items-center gap-4">
            <PrivateImage attachment={lightbox} className="max-h-[60vh] w-auto" />
            <Button variant="secondary" onClick={() => setLightbox(null)}>
              <X size={32} aria-hidden="true" />
              Close
            </Button>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={confirmDelete}
        title="Remove this attachment?"
        confirmLabel="Remove"
        tone="danger"
        description={`"${deleting?.filename ?? ''}" will be deleted permanently. The record that it was removed stays in the asset's history.`}
      />
    </div>
  )
}

/**
 * An image behind the authenticated API. The object URL is created on mount and
 * revoked on unmount — without the revoke, browsing a gallery would hold every
 * image it ever showed in memory for the life of the tab.
 */
function PrivateImage({
  attachment,
  className,
}: {
  attachment: AssetAttachment
  className?: string
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    fetchAttachmentObjectUrl(attachment.id)
      .then((created) => {
        if (cancelled) {
          URL.revokeObjectURL(created)
          return
        }
        objectUrl = created
        setUrl(created)
      })
      .catch(() => setFailed(true))

    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [attachment.id])

  if (failed) {
    return (
      <span className="flex h-48 items-center justify-center bg-surface-sunken text-sm text-muted">
        Preview unavailable
      </span>
    )
  }

  if (!url) return <Skeleton className="h-48 w-full" />

  return <img src={url} alt={attachment.caption ?? attachment.filename} className={className} />
}

/**
 * Documents stream through the API too, so a plain link cannot fetch one. This
 * pulls the blob and hands it to the browser as a download.
 */
function DownloadButton({ attachment }: { attachment: AssetAttachment }) {
  const [busy, setBusy] = useState(false)

  const download = async () => {
    setBusy(true)
    try {
      const url = await fetchAttachmentObjectUrl(attachment.id)
      const link = document.createElement('a')
      link.href = url
      link.download = attachment.filename
      link.click()
      URL.revokeObjectURL(url)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button variant="secondary" size="sm" onClick={download} disabled={busy}>
      {busy ? 'Opening…' : 'Download'}
    </Button>
  )
}
