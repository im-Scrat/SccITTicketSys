import { type RefObject, useLayoutEffect } from 'react'

interface OverlayPositionOptions {
  /** The preferred side; flips when the other side has more room. */
  placement?: 'bottom' | 'top'
  /** `start` aligns left edges (listboxes); `center` centres on the anchor (tooltips). */
  align?: 'start' | 'center'
  /** Gap between anchor and floating layer, in px. */
  offset?: number
  /** Make the floating layer at least as wide as the anchor. */
  matchWidth?: boolean
  /** Cap the layer's height to the room left on its side (scrolling lists). */
  fitHeight?: boolean
}

/** Keep the layer this far from the viewport edge. */
const EDGE = 8

/**
 * Positions a `position: fixed` layer against an anchor, flipping above it when
 * there is more room there and clamping it inside the viewport.
 *
 * Coordinates are written through the CSSOM (`element.style.top = …`), which
 * the production CSP (`style-src 'self'`) allows — unlike a `style=""` string
 * or an injected stylesheet. It re-measures on scroll anywhere (capture), on
 * resize, and when the layer's own size changes (a filtered list shrinking).
 */
export function useOverlayPosition(
  anchorRef: RefObject<HTMLElement | null>,
  floatingRef: RefObject<HTMLElement | null>,
  open: boolean,
  {
    placement = 'bottom',
    align = 'start',
    offset = 8,
    matchWidth = false,
    fitHeight = false,
  }: OverlayPositionOptions = {},
) {
  useLayoutEffect(() => {
    const anchor = anchorRef.current
    const floating = floatingRef.current
    if (!open || !anchor || !floating) return

    const update = () => {
      const rect = anchor.getBoundingClientRect()
      const viewportWidth = document.documentElement.clientWidth || window.innerWidth
      const viewportHeight = window.innerHeight

      if (matchWidth) floating.style.minWidth = `${rect.width}px`

      const below = viewportHeight - rect.bottom - offset - EDGE
      const above = rect.top - offset - EDGE
      const height = floating.offsetHeight
      const side =
        placement === 'bottom'
          ? height <= below || below >= above
            ? 'bottom'
            : 'top'
          : height <= above || above >= below
            ? 'top'
            : 'bottom'

      if (fitHeight)
        floating.style.maxHeight = `${Math.max(side === 'bottom' ? below : above, 120)}px`

      const finalHeight = floating.offsetHeight
      const width = floating.offsetWidth
      const top = side === 'bottom' ? rect.bottom + offset : rect.top - offset - finalHeight
      const preferredLeft = align === 'center' ? rect.left + rect.width / 2 - width / 2 : rect.left
      const left = Math.min(
        Math.max(preferredLeft, EDGE),
        Math.max(viewportWidth - width - EDGE, EDGE),
      )

      floating.style.top = `${Math.round(top)}px`
      floating.style.left = `${Math.round(left)}px`
      floating.dataset.placement = side
    }

    update()
    window.addEventListener('scroll', update, { capture: true, passive: true })
    window.addEventListener('resize', update)
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update)
    observer?.observe(floating)
    return () => {
      window.removeEventListener('scroll', update, { capture: true })
      window.removeEventListener('resize', update)
      observer?.disconnect()
    }
  }, [anchorRef, floatingRef, open, placement, align, offset, matchWidth, fitHeight])
}
