import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useViewportStore } from '../stores/useViewportStore'
import { pc, roomPlan } from '../test/fixtures'
import type { PlacedPc } from '../types'
import { FloorPlanCanvas } from './FloorPlanCanvas'

/**
 * WP-D — placing units on the map: pointer drag with capture, keyboard move
 * mode, and the touch model.
 *
 * jsdom has no layout engine, so the SVG is given a fixed 1000×600 box: one
 * screen pixel is one layout unit at 100%, which makes every expected point
 * exact. The canvas only *previews* and reports; what gets stored is the
 * server's business, tested in the page and Pest suites.
 */

const plan = roomPlan()
const layout = plan.layout!

const onPlace = vi.fn()
const onSelect = vi.fn()
const announce = vi.fn()
let setPointerCapture: ReturnType<typeof vi.fn>

function renderEditable(pcs: PlacedPc[] = [pc(1, 'online', { x: 120, y: 80 })], snap = true) {
  return render(
    <FloorPlanCanvas
      roomName={plan.room.name}
      layout={layout}
      pcs={pcs}
      editable
      snapToGrid={snap}
      onPlace={onPlace}
      onSelect={onSelect}
      announce={announce}
    />,
  )
}

const svg = () => screen.getByTestId('floor-plan-svg')
const viewBox = () => svg().getAttribute('viewBox')
const node = (name = /^PC-01/) => screen.getByRole('button', { name })

beforeEach(() => {
  onPlace.mockReset()
  onSelect.mockReset()
  announce.mockReset()
  useViewportStore.setState({ zoom: 1, x: 0, y: 0, content: null })
  setPointerCapture = vi.fn()
  Element.prototype.setPointerCapture = setPointerCapture as unknown as Element['setPointerCapture']
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
    x: 0,
    y: 0,
    left: 0,
    top: 0,
    right: 1000,
    bottom: 600,
    width: 1000,
    height: 600,
    toJSON: () => ({}),
  })
})

function press(target: Element, pointerId = 7, x = 120, y = 80, extra: object = {}) {
  fireEvent.pointerDown(target, {
    pointerId,
    clientX: x,
    clientY: y,
    button: 0,
    pointerType: 'mouse',
    ...extra,
  })
}

describe('editable units', () => {
  it('are focusable buttons named with identity, status and position', () => {
    renderEditable()

    const unit = node(/^PC-01, Online, at x 120, y 80$/)
    expect(unit).toHaveAttribute('tabindex', '0')
    expect(unit).toHaveAccessibleDescription(/press Enter/i)
  })

  it('stay pictures, not buttons, when the plan is not editable', () => {
    render(
      <FloorPlanCanvas roomName="Lab" layout={layout} pcs={[pc(1, 'online')]} onPlace={onPlace} />,
    )

    expect(screen.queryByRole('button', { name: /^PC-01/ })).toBeNull()
    expect(screen.getByRole('img', { name: /^PC-01/ })).toBeInTheDocument()
  })
})

describe('pointer drag', () => {
  it('captures the pointer on the unit, previews a snapped point, and reports the drop', () => {
    renderEditable()
    const unit = node()

    press(unit)
    expect(setPointerCapture).toHaveBeenCalledWith(7)

    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 167, clientY: 103, pointerType: 'mouse' })
    // 120+47 = 167 → 160; 80+23 = 103 → 100.
    expect(unit.getAttribute('transform')).toBe('translate(160 100)')
    expect(unit).toHaveAttribute('data-moving', 'true')
    expect(unit).toHaveAccessibleName('PC-01, Online, at x 160, y 100')

    fireEvent.pointerUp(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(onPlace).toHaveBeenCalledTimes(1)
    const [placedPc, point, snap] = onPlace.mock.calls[0]
    expect(placedPc.id).toBe('pc-uuid-1')
    expect(point).toEqual({ x: 160, y: 100 })
    expect(snap).toBe(true)
  })

  it('never moves the dragged element in the DOM — that would drop its pointer capture', () => {
    // Regression: drawing the lifted unit "last" by re-ordering the nodes made
    // React move its <g>, and a browser releases pointer capture (firing
    // lostpointercapture) the moment a captured element leaves the document.
    // Real drags were cancelled on the first move. jsdom does not emulate
    // capture, so the guard is on the cause: element identity and order.
    renderEditable([pc(1, 'online', { x: 120, y: 80 }), pc(2, 'offline', { x: 400, y: 300 })])
    const order = () => screen.getAllByTestId('floor-plan-node')
    const before = order()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 300, clientY: 300, pointerType: 'mouse' })

    const during = order()
    expect(during).toHaveLength(2)
    expect(during[0]).toBe(before[0])
    expect(during[1]).toBe(before[1])
    expect(during[0]).toBe(unit)

    // It is painted on top by an inert copy instead.
    const overlay = screen.getByTestId('floor-plan-lift-overlay')
    expect(overlay.getAttribute('href')).toBe(`#${unit.id}`)
    expect(overlay).toHaveAttribute('aria-hidden', 'true')
    expect(overlay).toHaveAttribute('pointer-events', 'none')
  })

  it('moves the unit, not the view', () => {
    renderEditable()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 300, clientY: 300, pointerType: 'mouse' })
    fireEvent.pointerUp(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('places off the grid while Shift is held', () => {
    renderEditable()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, {
      pointerId: 7,
      clientX: 167,
      clientY: 103,
      pointerType: 'mouse',
      shiftKey: true,
    })
    fireEvent.pointerUp(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(onPlace.mock.calls[0][1]).toEqual({ x: 167, y: 103 })
    expect(onPlace.mock.calls[0][2]).toBe(false)
  })

  it('does not snap when the snap default is off', () => {
    renderEditable(undefined, false)
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 167, clientY: 103, pointerType: 'mouse' })
    fireEvent.pointerUp(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(onPlace.mock.calls[0][1]).toEqual({ x: 167, y: 103 })
    expect(onPlace.mock.calls[0][2]).toBe(false)
  })

  it('keeps the preview on the canvas when dragged past its edge', () => {
    renderEditable()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, {
      pointerId: 7,
      clientX: 5000,
      clientY: -900,
      pointerType: 'mouse',
    })

    expect(unit.getAttribute('transform')).toBe('translate(1000 0)')
  })

  it('treats a press without travel as a click that selects the unit', () => {
    renderEditable()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 122, clientY: 81, pointerType: 'mouse' })
    fireEvent.pointerUp(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(onPlace).not.toHaveBeenCalled()
    expect(onSelect).toHaveBeenCalledWith('pc-uuid-1')
  })

  it('puts the unit back when Escape is pressed mid-drag', () => {
    renderEditable()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 300, clientY: 300, pointerType: 'mouse' })
    fireEvent.keyDown(window, { key: 'Escape' })
    fireEvent.pointerUp(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(unit.getAttribute('transform')).toBe('translate(120 80)')
    expect(onPlace).not.toHaveBeenCalled()
    expect(announce).toHaveBeenCalledWith('Move cancelled. PC-01 stays at x 120, y 80.')
  })

  it('puts the unit back when the browser cancels the pointer', () => {
    renderEditable()
    const unit = node()

    press(unit)
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 300, clientY: 300, pointerType: 'mouse' })
    fireEvent.pointerCancel(unit, { pointerId: 7, pointerType: 'mouse' })

    expect(unit.getAttribute('transform')).toBe('translate(120 80)')
    expect(onPlace).not.toHaveBeenCalled()
  })

  it('ignores a second pointer that is not the one dragging', () => {
    renderEditable()
    const unit = node()

    press(unit, 7)
    fireEvent.pointerMove(unit, { pointerId: 9, clientX: 400, clientY: 400, pointerType: 'mouse' })

    expect(unit.getAttribute('transform')).toBe('translate(120 80)')
  })

  it('ignores the right mouse button', () => {
    renderEditable()
    const unit = node()

    fireEvent.pointerDown(unit, {
      pointerId: 7,
      clientX: 120,
      clientY: 80,
      button: 2,
      pointerType: 'mouse',
    })
    fireEvent.pointerMove(unit, { pointerId: 7, clientX: 300, clientY: 300, pointerType: 'mouse' })

    expect(unit.getAttribute('transform')).toBe('translate(120 80)')
    expect(setPointerCapture).not.toHaveBeenCalled()
  })
})

describe('keyboard move mode — no dragging required', () => {
  it('Enter enters move mode and says how to use it', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{Enter}')

    expect(node()).toHaveAttribute('data-moving', 'true')
    expect(announce).toHaveBeenLastCalledWith(
      expect.stringMatching(/^Moving PC-01 from x 120, y 80\./),
    )
    expect(onSelect).toHaveBeenCalledWith('pc-uuid-1')
  })

  it('arrows move one grid cell, Shift + arrow one pixel, and the view does not pan', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{Enter}{ArrowRight}{ArrowRight}{ArrowDown}')
    expect(node().getAttribute('transform')).toBe('translate(160 100)')

    await user.keyboard('{Shift>}{ArrowLeft}{/Shift}')
    expect(node().getAttribute('transform')).toBe('translate(159 100)')
    expect(announce).toHaveBeenLastCalledWith('x 159, y 100')

    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('Enter commits the previewed point, asking for free placement after a pixel step', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{Enter}{ArrowRight}{Shift>}{ArrowDown}{/Shift}{Enter}')

    expect(onPlace).toHaveBeenCalledTimes(1)
    expect(onPlace.mock.calls[0][1]).toEqual({ x: 140, y: 81 })
    expect(onPlace.mock.calls[0][2]).toBe(false)
    expect(node()).not.toHaveAttribute('data-moving')
  })

  it('Enter commits grid steps with snapping on', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{Enter}{ArrowUp}{Enter}')

    expect(onPlace.mock.calls[0][1]).toEqual({ x: 120, y: 60 })
    expect(onPlace.mock.calls[0][2]).toBe(true)
  })

  it('Escape puts the unit back and says so', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{Enter}{ArrowRight}{ArrowRight}{Escape}')

    expect(node().getAttribute('transform')).toBe('translate(120 80)')
    expect(onPlace).not.toHaveBeenCalled()
    expect(announce).toHaveBeenLastCalledWith('Move cancelled. PC-01 stays at x 120, y 80.')
  })

  it('does not send a request for a unit that was not moved', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{Enter}{Enter}')

    expect(onPlace).not.toHaveBeenCalled()
    expect(announce).toHaveBeenLastCalledWith('PC-01 was not moved.')
  })

  it('abandons the move when focus leaves the unit', async () => {
    const user = userEvent.setup()
    renderEditable([pc(1, 'online', { x: 120, y: 80 }), pc(2, 'offline', { x: 400, y: 300 })])

    node().focus()
    await user.keyboard('{Enter}{ArrowRight}')
    await user.tab()

    expect(node().getAttribute('transform')).toBe('translate(120 80)')
    expect(onPlace).not.toHaveBeenCalled()
  })

  it('leaves the arrow keys to pan the view when a unit is focused but not moving', async () => {
    const user = userEvent.setup()
    renderEditable()

    node().focus()
    await user.keyboard('{ArrowRight}')

    expect(node().getAttribute('transform')).toBe('translate(120 80)')
    expect(viewBox()).toBe('150 0 1000 600')
  })
})

describe('touch', () => {
  it('lets a finger on the background scroll the page instead of panning the map', () => {
    renderEditable()

    expect(svg().getAttribute('class')).toContain('touch-manipulation')
    expect(svg().getAttribute('class')).not.toContain('touch-none')

    fireEvent.pointerDown(svg(), {
      pointerId: 3,
      clientX: 500,
      clientY: 500,
      button: 0,
      pointerType: 'touch',
    })
    fireEvent.pointerMove(svg(), { pointerId: 3, clientX: 400, clientY: 300, pointerType: 'touch' })

    expect(viewBox()).toBe('0 0 1000 600')
    expect(setPointerCapture).not.toHaveBeenCalled()
  })

  it('claims the touch only on an editable unit, so a finger can drag it', () => {
    renderEditable()
    const unit = node()

    expect(unit.style.touchAction).toBe('none')

    press(unit, 4, 120, 80, { pointerType: 'touch' })
    fireEvent.pointerMove(unit, { pointerId: 4, clientX: 220, clientY: 180, pointerType: 'touch' })
    fireEvent.pointerUp(unit, { pointerId: 4, pointerType: 'touch' })

    expect(onPlace.mock.calls[0][1]).toEqual({ x: 220, y: 180 })
  })
})
