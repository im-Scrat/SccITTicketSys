import { describe, expect, it } from 'vitest'
import {
  INITIAL_VIEWPORT,
  MAX_ZOOM,
  MIN_ZOOM,
  clampViewport,
  clampZoom,
  panBy,
  toViewBox,
  visibleSize,
  zoomAt,
} from './viewport'

const content = { width: 1000, height: 600 }

describe('viewport maths', () => {
  it('shows the whole layout at 100%', () => {
    expect(toViewBox(INITIAL_VIEWPORT, content)).toBe('0 0 1000 600')
  })

  it('shrinks the visible window as zoom grows', () => {
    expect(visibleSize(content, 2)).toEqual({ width: 500, height: 300 })
    expect(toViewBox({ zoom: 2, x: 100, y: 50 }, content)).toBe('100 50 500 300')
  })

  it('clamps zoom to the sensible limits', () => {
    expect(clampZoom(0.01)).toBe(MIN_ZOOM)
    expect(clampZoom(999)).toBe(MAX_ZOOM)
    expect(clampZoom(1.5)).toBe(1.5)
  })

  it('treats a non-finite zoom as 100% rather than propagating NaN', () => {
    expect(clampZoom(Number.NaN)).toBe(1)
    expect(clampZoom(Number.POSITIVE_INFINITY)).toBe(1)
  })

  it('keeps the point under the focus still while zooming', () => {
    // Zoom about the top-left corner of the window: it must not move.
    const next = zoomAt({ zoom: 1, x: 0, y: 0 }, content, 2, { u: 0, v: 0 })
    expect(next).toEqual({ zoom: 2, x: 0, y: 0 })

    // Zoom about the centre: the centre (500, 300) must stay the centre.
    const centred = zoomAt({ zoom: 1, x: 0, y: 0 }, content, 2)
    expect(centred.x + visibleSize(content, 2).width / 2).toBeCloseTo(500)
    expect(centred.y + visibleSize(content, 2).height / 2).toBeCloseTo(300)
  })

  it('never zooms past its limits', () => {
    expect(zoomAt(INITIAL_VIEWPORT, content, 100).zoom).toBe(MAX_ZOOM)
    expect(zoomAt(INITIAL_VIEWPORT, content, 0).zoom).toBe(MIN_ZOOM)
  })

  it('pans by layout units', () => {
    const start = { zoom: 2, x: 100, y: 100 }
    expect(panBy(start, content, 50, -20)).toEqual({ zoom: 2, x: 150, y: 80 })
  })

  it('cannot pan the plan wholly out of view', () => {
    const far = panBy(INITIAL_VIEWPORT, content, 99999, 99999)
    // The window's middle stays on the layout: x + visibleWidth/2 <= width.
    expect(far.x + content.width / 2).toBeLessThanOrEqual(content.width)
    expect(far.y + content.height / 2).toBeLessThanOrEqual(content.height)

    const other = panBy(INITIAL_VIEWPORT, content, -99999, -99999)
    expect(other.x + content.width / 2).toBeGreaterThanOrEqual(0)
    expect(other.y + content.height / 2).toBeGreaterThanOrEqual(0)
  })

  it('re-clamps a viewport whose zoom changed under it', () => {
    const clamped = clampViewport({ zoom: 4, x: 5000, y: 5000 }, content)
    expect(clamped.zoom).toBe(4)
    expect(clamped.x).toBeLessThan(content.width)
    expect(clamped.y).toBeLessThan(content.height)
  })
})
