import { vi } from 'vitest'

/**
 * jsdom has no layout and no SVG matrix API. The Floorplan canvas converts a
 * pointer with `getBoundingClientRect()` and the viewBox (`clientToLayout`), so
 * a test only needs a box: 1000 × 600 CSS pixels at the origin by default —
 * for a 1000 × 600 room at zoom 1, one pixel is one layout unit.
 *
 * Also: pointer capture (absent in jsdom) and an immediate
 * `requestAnimationFrame`, so a drag's preview applies synchronously.
 */
export function stubSvgGeometry(width = 1000, height = 600) {
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
    x: 0,
    y: 0,
    left: 0,
    top: 0,
    right: width,
    bottom: height,
    width,
    height,
    toJSON: () => ({}),
  })

  const proto = Element.prototype as unknown as Record<string, unknown>
  proto.setPointerCapture = vi.fn()
  proto.releasePointerCapture = vi.fn()
  proto.hasPointerCapture = vi.fn(() => false)

  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
    cb(0)
    return 0
  })
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => undefined)
}
