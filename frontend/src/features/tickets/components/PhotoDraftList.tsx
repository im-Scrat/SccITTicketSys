import { CircleAlert, X } from 'lucide-react'
import { Spinner } from '@/components/ui'
import type { PreparedPhotos } from '@/hooks/usePreparedPhotos'
import { cn } from '@/lib/cn'

interface PhotoDraftListProps {
  photos: PreparedPhotos
  disabled?: boolean
  /** A draft currently being sent, shown as "Uploading…" (the Photos & files tab). */
  uploadingId?: number | null
}

/**
 * Photos waiting to be sent, with a thumbnail, a name, a state and a way to
 * take each one back out.
 *
 * On a phone each photo is a row — a 64 px thumbnail, the name, and a 48 px
 * remove button — because a three-across grid of 80 px tiles leaves no room
 * for a name or an error message and buries the remove button on the photo.
 * From `sm` up the same items lay out as a grid of larger thumbnails.
 *
 * Thumbnails are made from the *prepared* file (already shrunk), never the
 * original 12-megapixel one, and are numbered — "Photo 2: image.jpg" — because
 * every photo an iPhone hands over is called `image.jpg`.
 *
 * A refusal or an upload is also *said*, through a polite live region that is
 * always in the page — present before the first photo, and after the last one
 * leaves — because a region that appears already holding its text is not
 * reliably read out. Each announcement is a new node (keyed by its id), so the
 * same sentence twice is still heard twice.
 *
 * The list clips its children only on a phone, where the rounded border is
 * drawn around it; from `sm` up there is no border to clip to, and clipping
 * would cut off the focus ring of a remove button at a tile's edge.
 */
export function PhotoDraftList({
  photos,
  disabled = false,
  uploadingId = null,
}: PhotoDraftListProps) {
  const { announcement } = photos

  return (
    <>
      <p aria-live="polite" className="sr-only">
        {announcement && <span key={announcement.id}>{announcement.text}</span>}
      </p>
      {photos.drafts.length > 0 && (
        <DraftItems photos={photos} disabled={disabled} uploadingId={uploadingId} />
      )}
    </>
  )
}

function DraftItems({ photos, disabled, uploadingId }: Required<PhotoDraftListProps>) {
  return (
    <ul className="flex flex-col divide-y divide-border overflow-hidden rounded-md border border-border sm:grid sm:grid-cols-3 sm:gap-4 sm:divide-y-0 sm:overflow-visible sm:border-0 lg:grid-cols-5">
      {photos.drafts.map((draft, index) => {
        const number = index + 1
        const uploading = uploadingId === draft.id

        return (
          <li
            key={draft.id}
            className="flex items-center gap-3 p-3 sm:flex-col sm:items-stretch sm:gap-2 sm:p-0"
          >
            <div
              className={cn(
                'flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-sm bg-surface-sunken sm:aspect-[4/3] sm:size-auto sm:w-full',
                draft.status === 'rejected' && 'text-danger-strong',
              )}
            >
              {draft.previewUrl ? (
                <img
                  src={draft.previewUrl}
                  alt={`Photo ${number}: ${draft.name}`}
                  className="size-full object-cover"
                />
              ) : draft.status === 'rejected' ? (
                <CircleAlert size={24} aria-hidden="true" />
              ) : (
                <Spinner size={20} className="text-muted" />
              )}
            </div>

            <div className="flex min-w-0 flex-1 items-start gap-2">
              <div className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-medium text-ink">
                  <span className="sr-only">Photo {number}: </span>
                  {draft.name}
                </span>
                {draft.status === 'preparing' && (
                  <span className="text-xs text-muted">Getting it ready…</span>
                )}
                {uploading && <span className="text-xs text-muted">Uploading…</span>}
                {draft.status === 'rejected' && draft.error && (
                  <span className="text-xs font-medium text-danger-strong">{draft.error}</span>
                )}
              </div>

              <button
                ref={photos.bindRemoveButton(draft.id)}
                type="button"
                onClick={() => photos.remove(draft.id)}
                disabled={disabled || uploading}
                aria-label={`Remove photo ${number} (${draft.name})`}
                className="flex size-12 shrink-0 items-center justify-center rounded-md border border-transparent text-muted transition-colors duration-150 hover:border-control-border hover:bg-surface-sunken hover:text-ink disabled:cursor-not-allowed disabled:text-faint disabled:hover:border-transparent disabled:hover:bg-transparent"
              >
                <X size={22} aria-hidden="true" />
              </button>
            </div>
          </li>
        )
      })}
    </ul>
  )
}
