import { type RefObject, useEffect, useRef } from 'react'
import { useFocusTrap } from '@/hooks/useFocusTrap'
import { useInertBackground } from '@/hooks/useInertBackground'

/**
 * The overlays open right now, innermost last — Modals and Drawers alike.
 * Escape is a document-level key, so without one shared stack every open
 * overlay would hear the same press: cancelling a confirmation raised from a
 * drawer used to close the drawer behind it too. Only the topmost answers.
 */
const openOverlays: symbol[] = []

interface OverlayLayerOptions {
  open: boolean
  onClose: () => void
  /** The portalled root (siblings of it become inert). */
  rootRef: RefObject<HTMLElement | null>
  /** The dialog element (focus moves here and Tab is kept inside it). */
  dialogRef: RefObject<HTMLElement | null>
  /** Focus this on open instead of the dialog itself. */
  initialFocusRef?: RefObject<HTMLElement | null>
  /** Return focus here on close instead of whatever opened the overlay. */
  returnFocusRef?: RefObject<HTMLElement | null>
}

/**
 * Everything a modal overlay owes the page, in one place so Modal and Drawer
 * cannot drift apart again: the shared Escape stack, scroll lock, focus in on
 * open and back on close, a Tab trap, and an inert background.
 */
export function useOverlayLayer({
  open,
  onClose,
  rootRef,
  dialogRef,
  initialFocusRef,
  returnFocusRef,
}: OverlayLayerOptions) {
  // Read through refs so a parent passing fresh functions or refs each render
  // does not re-run the effect, which would re-stack the overlay and steal focus.
  const onCloseRef = useRef(onClose)
  const initialRef = useRef(initialFocusRef)
  const returnRef = useRef(returnFocusRef)
  useEffect(() => {
    onCloseRef.current = onClose
    initialRef.current = initialFocusRef
    returnRef.current = returnFocusRef
  })

  useFocusTrap(dialogRef, open)
  useInertBackground(rootRef, open)

  useEffect(() => {
    if (!open) return
    const token = Symbol('overlay')
    openOverlays.push(token)

    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const dialog = dialogRef.current
    const preferred = initialRef.current?.current
    if (preferred) preferred.focus()
    // Content that already took focus (an autoFocus field) keeps it.
    else if (!dialog?.contains(document.activeElement)) dialog?.focus()

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && openOverlays[openOverlays.length - 1] === token) {
        onCloseRef.current()
      }
    }
    document.addEventListener('keydown', onKey)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
      openOverlays.splice(openOverlays.indexOf(token), 1)
      // useInertBackground is declared first, so React has already released the
      // background (cleanups run in declaration order) and the target is
      // focusable again. Only if it is still on the page: an overlay that
      // deleted the row it was opened from has nothing to return to.
      const target = returnRef.current?.current ?? opener
      if (target?.isConnected) target.focus()
    }
    // dialogRef is a stable ref object.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])
}
