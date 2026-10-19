import { useId } from 'react'
import { PhotoPickerButtons } from '@/components/PhotoPickerButtons'
import type { PreparedPhotos } from '@/hooks/usePreparedPhotos'
import { PhotoDraftList } from './PhotoDraftList'

/** How many photos the report form offers. Not a server limit — what the form takes. */
export const REPORT_PHOTO_LIMIT = 5

interface ReportPhotosProps {
  photos: PreparedPhotos
  max?: number
  disabled?: boolean
  /** id for the picker button, so focus can return to it when the last photo is removed. */
  pickerId: string
}

/**
 * The report form's "Photos" section (SRS FR-TKT-008; SDD DD-80).
 *
 * Photos are taken or chosen while the report is being written, not afterwards
 * on the ticket page — the teacher is standing at the machine now, with the
 * phone already in hand. They are uploaded one by one after the report itself
 * has been created, so a photo that fails never costs the report.
 *
 * The count ("2 of 5") is a polite live region: adding from the gallery
 * happens in another app, and coming back to a list that silently grew (or
 * didn't) is otherwise invisible to a screen-reader user.
 *
 * **The picker stays enabled at the limit.** Focus returns to it from the
 * file dialog; disabling it as the fifth photo arrives would drop that focus
 * to the page body. A pick beyond the limit is refused by the photo list
 * instead, with the same "up to 5" notice — which is always in the page, so a
 * screen reader hears it when its text changes.
 */
export function ReportPhotos({
  photos,
  max = REPORT_PHOTO_LIMIT,
  disabled = false,
  pickerId,
}: ReportPhotosProps) {
  const hintId = useId()

  return (
    <div className="flex flex-col gap-4">
      <p id={hintId} className="measure text-sm text-muted">
        Optional. A photo of the screen or the machine often explains a problem fastest — up to{' '}
        {max}, as JPEG, PNG, WebP or GIF.
      </p>

      <PhotoPickerButtons
        onPick={(files) => void photos.add(files)}
        disabled={disabled}
        primaryId={pickerId}
        describedBy={hintId}
      />

      <p aria-live="polite" className="text-sm font-medium text-ink tnum">
        {photos.count} of {max} {photos.count === 1 ? 'photo' : 'photos'}
        {photos.atLimit ? ' — that’s the most this form takes.' : ''}
      </p>

      {/* Out of the layout while empty (no gap for an empty line), never out of the page. */}
      <p
        role="status"
        className={photos.notice ? 'text-sm font-medium text-warning-strong' : 'sr-only'}
      >
        {photos.notice}
      </p>

      <PhotoDraftList photos={photos} disabled={disabled} />
    </div>
  )
}
