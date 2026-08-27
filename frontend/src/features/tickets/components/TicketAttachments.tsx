import { FileText, Trash2, Upload, X } from 'lucide-react'
import { type ChangeEvent, useEffect, useState } from 'react'
import { Alert, Button, ConfirmDialog, EmptyState, Modal, Skeleton } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import { fetchAttachmentObjectUrl } from '../api/ticketsApi'
import { useDeleteAttachment, useUploadAttachment } from '../hooks/mutations'
import { useTicketAttachments } from '../hooks/queries'
import type { TicketAttachment } from '../types'

/** Kept in step with `StoreTicketAttachmentRequest`'s allow-list. */
const ACCEPT = 'image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain'

interface TicketAttachmentsProps {
  ticketId: string
  canManage: boolean
}

/**
 * Evidence attached to a ticket (SRS FR-TKT-008).
 *
 * Attachments follow the **full** projection, never the community card: a
 * requester browsing the feed learns that a ticket exists, not what was
 * photographed inside a staff room. The server refuses this endpoint for anyone
 * without full visibility, so a card-level reader never even reaches it.
 *
 * Files live on a private disk, so an `<img src>` cannot fetch one: each
 * thumbnail pulls its bytes through the authenticated API client and holds an
 * object URL, revoked on unmount so the tab does not leak blobs. Same mechanism
 * as the asset gallery, and for the same reason.
 */
export function TicketAttachments({ ticketId, canManage }: TicketAttachmentsProps) {
  const { data, isLoading, isError } = useTicketAttachments(ticketId)
  const upload = useUploadAttachment(ticketId)
  const remove = useDeleteAttachment()

  const [error, setError] = useState<string | null>(null)
  const [lightbox, setLightbox] = useState<TicketAttachment | null>(null)
  const [deleting, setDeleting] = useState<TicketAttachment | null>(null)

  const onPick = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = '' // allow re-picking the same file after an error
    if (!file) return

    setError(null)
    try {
      await upload.mutateAsync(file)
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

  if (isError) {
    return <Alert tone="error">The attachments for this ticket could not be loaded.</Alert>
  }

  const images = (data ?? []).filter((item) => item.is_image)
  const documents = (data ?? []).filter((item) => !item.is_image)

  return (
    <div className="flex flex-col gap-8">
      {error && <Alert tone="error">{error}</Alert>}

      {canManage && (
        <div>
          <label className="inline-flex h-15 cursor-pointer items-center gap-3 rounded-md border border-control-border bg-surface px-6 text-sm font-semibold text-ink shadow-sm hover:bg-surface-sunken">
            <Upload size={32} aria-hidden="true" />
            {upload.isPending ? 'Uploading…' : 'Add a photo or file'}
            <input
              type="file"
              className="sr-only"
              accept={ACCEPT}
              onChange={onPick}
              disabled={upload.isPending}
            />
          </label>
          <p className="mt-2 text-sm text-muted">
            A photo of the screen or the machine, a PDF, or a plain-text log. Up to 10 MB.
          </p>
        </div>
      )}

      {(data ?? []).length === 0 ? (
        <EmptyState
          title="No photos or files yet"
          description="A photograph of the error on screen is usually the fastest way to explain a fault."
        />
      ) : (
        <>
          {images.length > 0 && (
            <section>
              <h3 className="text-base font-bold text-ink-strong">Photos</h3>
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
                          {image.filename}
                        </span>
                        <span className="block text-xs text-muted">
                          {image.uploaded_by ?? 'Unknown'} · {formatDateTime(image.uploaded_at)}
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
              <h3 className="text-base font-bold text-ink-strong">Files</h3>
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
                          {document.filename}
                        </span>
                        <span className="block text-xs text-muted">
                          {document.uploaded_by ?? 'Unknown'} ·{' '}
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
        title={lightbox?.filename ?? 'Photo'}
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
        loading={remove.isPending}
        description={`"${deleting?.filename ?? ''}" will be deleted permanently. The record that it was removed stays in the ticket's history.`}
      />
    </div>
  )
}

/**
 * An image behind the authenticated API. The object URL is created on mount and
 * revoked on unmount — without the revoke, browsing a ticket's evidence would
 * hold every image it ever showed in memory for the life of the tab.
 */
function PrivateImage({
  attachment,
  className,
}: {
  attachment: TicketAttachment
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

  return <img src={url} alt={attachment.filename} className={className} />
}

/**
 * Files stream through the API too, so a plain link cannot fetch one. This pulls
 * the blob and hands it to the browser as a download.
 */
function DownloadButton({ attachment }: { attachment: TicketAttachment }) {
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
