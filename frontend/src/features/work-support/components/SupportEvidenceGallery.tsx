import { FileText, Paperclip } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Modal } from '@/components/ui'
import { fetchAttachmentBlob } from '../api/workSupportApi'
import type { SupportRequestAttachment } from '../types'

/**
 * The evidence a technician attached to a support request (SRS FR-WSR-003/005).
 *
 * ── Why this exists rather than reusing the gallery outright ───────────────
 *
 * `RepairEvidenceGallery` is bound to a maintenance record: it groups by
 * before/during/after, it uploads, and it deletes. A support request's evidence
 * has no stages and is immutable once submitted — what the administrator needs
 * is to *look at it while deciding*. So this reuses the gallery's **pattern**
 * exactly — bytes through the authenticated client, an object URL held and
 * revoked, never an `<img src>` pointed at the route — without inheriting three
 * affordances that would be wrong here.
 *
 * ── The rule the pattern exists to keep ────────────────────────────────────
 *
 * Files live on a private disk, so a URL cannot fetch one. Every preview pulls
 * its bytes through the API client, which is also what lets the server force
 * `Content-Disposition: attachment` on a PDF without breaking image previews
 * (DD-45). No storage path is ever exposed, because there is no storage path in
 * the payload to expose.
 */
export function SupportEvidenceGallery({
  requestId,
  attachments,
}: {
  requestId: string
  attachments: SupportRequestAttachment[]
}) {
  const [lightbox, setLightbox] = useState<SupportRequestAttachment | null>(null)

  if (attachments.length === 0) return null

  return (
    <div className="flex flex-col gap-2">
      <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted">
        <Paperclip className="size-3.5" aria-hidden="true" />
        {attachments.length === 1
          ? 'Attached evidence'
          : `Attached evidence (${attachments.length})`}
      </p>

      <ul className="flex flex-wrap gap-3">
        {attachments.map((file) => (
          <li key={file.id}>
            <button
              type="button"
              onClick={() => setLightbox(file)}
              className="block overflow-hidden rounded-md border-2 border-border bg-surface transition-colors duration-150 hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              aria-label={`Open ${file.caption ?? file.filename ?? 'evidence'}`}
            >
              <AttachmentPreview requestId={requestId} attachment={file} />
              <span className="block max-w-40 truncate px-2 py-1.5 text-left text-xs text-muted">
                {file.filename ?? 'Evidence'}
              </span>
            </button>
          </li>
        ))}
      </ul>

      <Modal
        open={lightbox !== null}
        onClose={() => setLightbox(null)}
        title={lightbox?.filename ?? 'Evidence'}
        size="lg"
      >
        {lightbox !== null && (
          <div className="flex flex-col gap-4">
            <AttachmentPreview requestId={requestId} attachment={lightbox} large />
            {lightbox.caption && <p className="text-sm text-muted">{lightbox.caption}</p>}
          </div>
        )}
      </Modal>
    </div>
  )
}

/**
 * Pull the bytes through the authenticated client and hold an object URL.
 *
 * Revoked on unmount and whenever the attachment changes, so an administrator
 * working through an inbox does not accumulate blobs in the tab.
 */
function AttachmentPreview({
  requestId,
  attachment,
  large = false,
}: {
  requestId: string
  attachment: SupportRequestAttachment
  large?: boolean
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    if (attachment.kind !== 'image') return

    let cancelled = false
    let objectUrl: string | null = null

    fetchAttachmentBlob(requestId, attachment.id)
      .then((blob) => {
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })

    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [requestId, attachment.id, attachment.kind])

  const frame = large ? 'max-h-[70vh] w-full object-contain' : 'size-28 object-cover'

  if (attachment.kind !== 'image') {
    return (
      <span
        className={`flex items-center justify-center bg-surface-sunken ${large ? 'h-64' : 'size-28'}`}
      >
        <FileText className="size-8 text-muted" aria-hidden="true" />
      </span>
    )
  }

  if (failed) {
    // The broken-attachment state: a row whose bytes are gone, or a download the
    // server refused. Named rather than left as an empty box, so nobody assumes
    // the evidence was never attached.
    return (
      <span
        className={`flex items-center justify-center bg-surface-sunken px-2 text-center text-xs text-muted ${large ? 'h-64' : 'size-28'}`}
        role="status"
      >
        Preview unavailable
      </span>
    )
  }

  if (url === null) {
    return (
      <span className={`block animate-pulse bg-surface-sunken ${large ? 'h-64' : 'size-28'}`} />
    )
  }

  return (
    <img
      src={url}
      // The caption is the technician's own description; the filename is the
      // fallback. Never an empty alt — this image carries the evidence.
      alt={attachment.caption ?? attachment.filename ?? 'Attached evidence'}
      className={frame}
    />
  )
}
