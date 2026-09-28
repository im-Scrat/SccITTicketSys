import { beforeEach, describe, expect, it } from 'vitest'
import { MAX_ZOOM, MIN_ZOOM, ZOOM_STEP } from '../lib/viewport'
import { useViewportStore } from './useViewportStore'

const size = { width: 1000, height: 600 }

function view() {
  const { zoom, x, y } = useViewportStore.getState()
  return { zoom, x, y }
}

describe('useViewportStore', () => {
  beforeEach(() => {
    useViewportStore.setState({ zoom: 1, x: 0, y: 0, content: null })
  })

  it('does nothing until a layout has told it its size', () => {
    useViewportStore.getState().zoomIn()
    useViewportStore.getState().panByUnits(50, 50)

    expect(view()).toEqual({ zoom: 1, x: 0, y: 0 })
  })

  it('zooms in and out by one step and stays within limits', () => {
    const store = useViewportStore.getState()
    store.setContent(size)

    store.zoomIn()
    expect(useViewportStore.getState().zoom).toBeCloseTo(ZOOM_STEP)

    store.zoomOut()
    expect(useViewportStore.getState().zoom).toBeCloseTo(1)

    for (let i = 0; i < 40; i++) store.zoomIn()
    expect(useViewportStore.getState().zoom).toBe(MAX_ZOOM)

    for (let i = 0; i < 80; i++) store.zoomOut()
    expect(useViewportStore.getState().zoom).toBe(MIN_ZOOM)
  })

  it('pans by units and by steps', () => {
    const store = useViewportStore.getState()
    store.setContent(size)

    store.panByUnits(100, 40)
    expect(view()).toMatchObject({ x: 100, y: 40 })

    // One step right at 100% is 15% of the 1000-wide window.
    store.panByStep(1, 0)
    expect(useViewportStore.getState().x).toBeCloseTo(250)
  })

  it('resets to the whole layout', () => {
    const store = useViewportStore.getState()
    store.setContent(size)
    store.zoomIn()
    store.panByUnits(200, 100)

    store.reset()

    expect(view()).toEqual({ zoom: 1, x: 0, y: 0 })
  })

  it('starts fresh when a different-sized layout is loaded, and keeps the view when the size is unchanged', () => {
    const store = useViewportStore.getState()
    store.setContent(size)
    store.zoomIn()

    store.setContent(size)
    expect(useViewportStore.getState().zoom).toBeGreaterThan(1)

    store.setContent({ width: 800, height: 500 })
    expect(view()).toEqual({ zoom: 1, x: 0, y: 0 })
  })

  it('holds viewport state only — no domain, server or permission data', () => {
    const keys = Object.keys(useViewportStore.getState())
      .filter((key) => typeof useViewportStore.getState()[key as never] !== 'function')
      .sort()

    expect(keys).toEqual(['content', 'x', 'y', 'zoom'])
  })
})
