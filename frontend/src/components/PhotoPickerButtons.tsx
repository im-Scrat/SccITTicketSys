import { Camera, ImagePlus } from 'lucide-react'
import { type ChangeEvent, useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui'
import { PHOTO_ACCEPT } from '@/lib/images'

const COARSE_POINTER = '(pointer: coarse)'

function matchesCoarsePointer(): boolean {
  try {
    return typeof window !== 'undefined' && window.matchMedia?.(COARSE_POINTER).matches === true
  } catch {
    return false
  }
}

/** Whether the main pointer is a finger — the closest signal there is for "has a camera to hand". */
function useCoarsePointer(): boolean {
  const [coarse, setCoarse] = useState(matchesCoarsePointer)

  useEffect(() => {
    let query: MediaQueryList | undefined
    try {
      query = window.matchMedia?.(COARSE_POINTER)
    } catch {
      return
    }
    if (!query?.addEventListener) return

    const onChange = (event: MediaQueryListEvent) => setCoarse(event.matches)
    query.addEventListener('change', onChange)
    return () => query?.removeEventListener('change', onChange)
  }, [])

  return coarse
}

interface PhotoPickerButtonsProps {
  onPick: (files: File[]) => void
  disabled?: boolean
  /** Lets the gallery picker take several photos at once. */
  multiple?: boolean
  /** id for the always-present button, so a caller can return focus to it. */
  primaryId?: string
  /** Points the buttons at the help text that explains what may be added. */
  describedBy?: string
  galleryLabel?: string
}

/**
 * The two ways to add a photograph: take one now, or choose existing ones.
 *
 * **Kit buttons open hidden inputs**, the ProofOfWorkForm pattern. A file input
 * cannot be styled, and the usual workaround — an `sr-only` input inside a
 * styled label — puts keyboard focus on a one-pixel box nobody can see. Here
 * focus lands on a real button with the product's focus ring, and the inputs
 * are `tabIndex={-1}` and `aria-hidden` so they are never a second, unlabelled
 * stop for the keyboard or a screen reader.
 *
 * **"Take a photo" appears only for a touch pointer.** `capture` is ignored by
 * desktop browsers, which would show the ordinary file dialog under a button
 * promising a camera; a desktop gets the one honest button instead.
 *
 * The inputs' `value` is cleared after every pick so the same photograph can be
 * chosen again after it was removed or refused.
 */
export function PhotoPickerButtons({
  onPick,
  disabled = false,
  multiple = true,
  primaryId,
  describedBy,
  galleryLabel,
}: PhotoPickerButtonsProps) {
  const coarse = useCoarsePointer()
  const cameraInput = useRef<HTMLInputElement>(null)
  const galleryInput = useRef<HTMLInputElement>(null)

  const picked = (event: ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(event.target.files ?? [])
    event.target.value = ''
    if (files.length > 0) onPick(files)
  }

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
      {coarse && (
        <Button
          variant="secondary"
          className="w-full sm:w-auto"
          leftIcon={<Camera size={22} aria-hidden="true" />}
          onClick={() => cameraInput.current?.click()}
          disabled={disabled}
          aria-describedby={describedBy}
        >
          Take a photo
        </Button>
      )}
      <Button
        id={primaryId}
        variant="secondary"
        className="w-full sm:w-auto"
        leftIcon={<ImagePlus size={22} aria-hidden="true" />}
        onClick={() => galleryInput.current?.click()}
        disabled={disabled}
        aria-describedby={describedBy}
      >
        {galleryLabel ?? (coarse ? 'Choose from gallery' : 'Choose photos')}
      </Button>

      {coarse && (
        <input
          ref={cameraInput}
          type="file"
          accept={PHOTO_ACCEPT}
          capture="environment"
          className="sr-only"
          tabIndex={-1}
          aria-hidden="true"
          data-testid="photo-camera-input"
          onChange={picked}
        />
      )}
      <input
        ref={galleryInput}
        type="file"
        accept={PHOTO_ACCEPT}
        multiple={multiple}
        className="sr-only"
        tabIndex={-1}
        aria-hidden="true"
        data-testid="photo-gallery-input"
        onChange={picked}
      />
    </div>
  )
}
