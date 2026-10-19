import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

/**
 * Ask before leaving a Floorplan with unsaved changes (Phase 2.11, DD-101).
 *
 * The app uses `BrowserRouter`, not a data router, so React Router's blocker
 * is unavailable. Two guards cover what can be guarded:
 *
 *  - **Closing or reloading the tab** — the browser's own `beforeunload` prompt.
 *  - **Following an in-app link** (the sidebar, a breadcrumb, a ticket link) — a
 *    capture-phase click listener stops a same-origin `<a>` navigation and
 *    hands its target to the page, which asks Save / Leave / Stay.
 *
 * The browser's Back button cannot be stopped under `BrowserRouter`; the draft
 * kept in session storage (`draftStorage`) is what makes that safe — returning
 * to the room offers the unsaved work back.
 */
export function useUnsavedChangesGuard(active: boolean) {
  const navigate = useNavigate()
  const [pending, setPending] = useState<string | null>(null)
  const activeRef = useRef(active)
  activeRef.current = active

  useEffect(() => {
    if (!active) return

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      // Required by some browsers to show the prompt at all.
      event.returnValue = ''
    }

    const onClick = (event: MouseEvent) => {
      if (!activeRef.current || event.defaultPrevented || event.button !== 0) return
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

      const anchor = (event.target as Element | null)?.closest?.('a[href]')
      if (!(anchor instanceof HTMLAnchorElement)) return
      if (anchor.target && anchor.target !== '_self') return
      if (anchor.hasAttribute('download')) return

      const url = new URL(anchor.href, window.location.href)
      if (url.origin !== window.location.origin) return
      const to = url.pathname + url.search + url.hash
      if (to === window.location.pathname + window.location.search + window.location.hash) return

      event.preventDefault()
      event.stopPropagation()
      setPending(to)
    }

    window.addEventListener('beforeunload', onBeforeUnload)
    document.addEventListener('click', onClick, true)

    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload)
      document.removeEventListener('click', onClick, true)
    }
  }, [active])

  return {
    /** Where the administrator was heading, while the page asks what to do. */
    pending,
    stay: () => setPending(null),
    leave: () => {
      const to = pending
      setPending(null)
      activeRef.current = false
      if (to) navigate(to)
    },
  }
}
