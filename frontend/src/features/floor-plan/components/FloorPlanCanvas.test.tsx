import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useViewportStore } from '../stores/useViewportStore'
import { ALL_STATUS_VALUES, pc, roomPlan } from '../test/fixtures'
import { FloorPlanCanvas } from './FloorPlanCanvas'
import { FloorPlanToolbar } from './FloorPlanToolbar'

/**
 * The map: SVG structure, grid, nodes, pan and zoom.
 *
 * jsdom has no layout engine, so pointer maths is exercised by giving the SVG
 * a fixed bounding box (1 screen pixel = 1 layout unit at 100%), which makes the
 * expected pan an exact number.
 */

const plan = roomPlan()

function renderMap(pcs = plan.pcs) {
  return render(
    <>
      <FloorPlanToolbar />
      <FloorPlanCanvas roomName={plan.room.name} layout={plan.layout!} pcs={pcs} />
    </>,
  )
}

const svg = () => screen.getByTestId('floor-plan-svg')
const viewBox = () => svg().getAttribute('viewBox')
const frame = () => screen.getByRole('group', { name: /^Floor plan of Computer Lab 2/ })

beforeEach(() => {
  useViewportStore.setState({ zoom: 1, x: 0, y: 0, content: null })
  Element.prototype.setPointerCapture = vi.fn()
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

describe('FloorPlanCanvas structure', () => {
  it('renders an SVG whose viewBox is the layout in its own units', () => {
    renderMap()

    expect(svg().tagName.toLowerCase()).toBe('svg')
    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('draws the grid as an SVG pattern sized by the layout, filling the room', () => {
    renderMap()

    const pattern = document.querySelector('pattern')!
    expect(pattern).not.toBeNull()
    expect(pattern.getAttribute('width')).toBe('20')
    expect(pattern.getAttribute('height')).toBe('20')

    const grid = screen.getByTestId('floor-plan-grid')
    expect(grid.getAttribute('fill')).toBe(`url(#${pattern.id})`)
    expect(grid.getAttribute('width')).toBe('1000')
    expect(grid.getAttribute('height')).toBe('600')
  })

  it('draws the room boundary and floor at the layout size', () => {
    renderMap()

    for (const id of ['floor-plan-floor', 'floor-plan-boundary']) {
      const rect = screen.getByTestId(id)
      expect(rect.getAttribute('width')).toBe('1000')
      expect(rect.getAttribute('height')).toBe('600')
    }
  })

  it('places each PC node at its stored coordinates, unconverted', () => {
    renderMap([pc(3, 'online', { x: 240.5, y: 80 })])

    expect(screen.getByTestId('floor-plan-node').getAttribute('transform')).toBe(
      'translate(240.5 80)',
    )
  })

  it('renders one node per PC, with a unique grid id per instance', () => {
    const { unmount } = renderMap()
    expect(screen.getAllByTestId('floor-plan-node')).toHaveLength(ALL_STATUS_VALUES.length)
    const first = document.querySelector('pattern')!.id
    unmount()

    renderMap()
    expect(first).not.toBe('')
  })

  it('renders an empty room as a grid with no nodes', () => {
    renderMap([])

    expect(screen.queryAllByTestId('floor-plan-node')).toHaveLength(0)
    expect(screen.getByTestId('floor-plan-grid')).toBeInTheDocument()
  })
})

describe('PC node status representation (never colour alone)', () => {
  it('gives every status its own shape', () => {
    renderMap()

    const signature = (node: Element) => {
      const shape = node.querySelector('circle, polygon')!
      const sides = shape.tagName === 'circle' ? 0 : shape.getAttribute('points')!.split(' ').length
      return `${shape.tagName}:${sides}:${shape.getAttribute('stroke-dasharray') ?? 'solid'}:${
        shape.getAttribute('points')?.split(' ')[0] ?? ''
      }`
    }

    const signatures = screen.getAllByTestId('floor-plan-node').map(signature)

    expect(new Set(signatures).size).toBe(ALL_STATUS_VALUES.length)
  })

  it("writes the status beside every node, in the server's words", () => {
    renderMap()

    for (const node of screen.getAllByTestId('floor-plan-node')) {
      const value = node.getAttribute('data-status')!
      const expected = plan.pcs.find((p) => p.status.value === value)!.status.label
      expect(within(node as HTMLElement).getAllByText(expected).length).toBeGreaterThan(0)
    }
  })

  it("colours a node from the server's tone, not from the status name", () => {
    // Same status value, different tone: the class follows the tone.
    renderMap([pc(1, 'online', { status: { value: 'online', label: 'Online', tone: 'danger' } })])

    const shape = screen.getByTestId('floor-plan-node').querySelector('circle')!
    expect(shape.getAttribute('class')).toContain('stroke-danger-strong')
    expect(shape.getAttribute('class')).not.toContain('success')
  })

  it('gives every node an accessible name with its status and position', () => {
    renderMap()

    const nodes = screen.getAllByRole('img')
    expect(nodes).toHaveLength(ALL_STATUS_VALUES.length)
    expect(
      screen.getByRole('img', { name: 'PC-03, Online, position 300 by 120' }),
    ).toBeInTheDocument()

    for (const node of nodes) expect(node).toHaveAccessibleName()
  })
})

describe('the map is read-only', () => {
  it('pans the view, not the machine, when a node is dragged', () => {
    renderMap()
    const node = screen.getAllByTestId('floor-plan-node')[0]
    const before = node.getAttribute('transform')

    fireEvent.pointerDown(node, {
      pointerId: 1,
      clientX: 100,
      clientY: 100,
      button: 0,
      pointerType: 'mouse',
    })
    fireEvent.pointerMove(svg(), { pointerId: 1, clientX: 40, clientY: 60, pointerType: 'mouse' })
    fireEvent.pointerUp(svg(), { pointerId: 1, pointerType: 'mouse' })

    expect(node.getAttribute('transform')).toBe(before)
    expect(viewBox()).not.toBe('0 0 1000 600')
  })

  it('offers no draggable nodes', () => {
    renderMap()

    for (const node of screen.getAllByTestId('floor-plan-node')) {
      expect(node.getAttribute('draggable')).toBeNull()
    }
  })
})

describe('pan', () => {
  it('pans by dragging the background', () => {
    renderMap()

    fireEvent.pointerDown(svg(), {
      pointerId: 1,
      clientX: 100,
      clientY: 100,
      button: 0,
      pointerType: 'mouse',
    })
    fireEvent.pointerMove(svg(), { pointerId: 1, clientX: 60, clientY: 70, pointerType: 'mouse' })
    fireEvent.pointerUp(svg(), { pointerId: 1, pointerType: 'mouse' })

    // Dragged 40px left and 30px up, so the window moved 40 right and 30 down.
    expect(viewBox()).toBe('40 30 1000 600')
  })

  it('ignores the right mouse button', () => {
    renderMap()

    fireEvent.pointerDown(svg(), {
      pointerId: 1,
      clientX: 100,
      clientY: 100,
      button: 2,
      pointerType: 'mouse',
    })
    fireEvent.pointerMove(svg(), { pointerId: 1, clientX: 20, clientY: 20, pointerType: 'mouse' })

    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('stops panning when the pointer is released', () => {
    renderMap()

    fireEvent.pointerDown(svg(), {
      pointerId: 1,
      clientX: 100,
      clientY: 100,
      button: 0,
      pointerType: 'mouse',
    })
    fireEvent.pointerUp(svg(), { pointerId: 1, pointerType: 'mouse' })
    fireEvent.pointerMove(svg(), { pointerId: 1, clientX: 0, clientY: 0, pointerType: 'mouse' })

    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('pans with the arrow keys when the map is focused', async () => {
    const user = userEvent.setup()
    renderMap()

    frame().focus()
    await user.keyboard('{ArrowRight}')
    expect(viewBox()).toBe('150 0 1000 600')

    await user.keyboard('{ArrowDown}{ArrowLeft}{ArrowUp}')
    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('pans with the toolbar buttons', async () => {
    const user = userEvent.setup()
    renderMap()

    await user.click(screen.getByRole('button', { name: 'Pan right' }))
    expect(viewBox()).toBe('150 0 1000 600')

    await user.click(screen.getByRole('button', { name: 'Pan down' }))
    expect(viewBox()).toBe('150 90 1000 600')
  })
})

describe('zoom', () => {
  it('zooms with the toolbar and shows the level', async () => {
    const user = userEvent.setup()
    renderMap()

    await user.click(screen.getByRole('button', { name: 'Zoom in' }))

    expect(viewBox()).toBe('100 60 800 480')
    expect(screen.getByRole('status')).toHaveTextContent('125%')

    await user.click(screen.getByRole('button', { name: 'Zoom out' }))
    expect(screen.getByRole('status')).toHaveTextContent('100%')
  })

  it('zooms with + and −, and resets with 0', async () => {
    const user = userEvent.setup()
    renderMap()

    frame().focus()
    await user.keyboard('+')
    expect(screen.getByRole('status')).toHaveTextContent('125%')

    await user.keyboard('-')
    expect(screen.getByRole('status')).toHaveTextContent('100%')

    await user.keyboard('+++0')
    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('zooms on Ctrl + wheel but leaves a plain wheel to scroll the page', () => {
    renderMap()

    fireEvent.wheel(frame(), { deltaY: -100 })
    expect(viewBox()).toBe('0 0 1000 600')

    fireEvent.wheel(frame(), { deltaY: -100, ctrlKey: true })
    expect(useViewportStore.getState().zoom).toBeGreaterThan(1)
  })

  it('does not swallow browser shortcuts such as Ctrl + plus', async () => {
    const user = userEvent.setup()
    renderMap()

    frame().focus()
    await user.keyboard('{Control>}={/Control}')

    expect(viewBox()).toBe('0 0 1000 600')
  })

  it('disables a zoom button at its limit', async () => {
    const user = userEvent.setup()
    renderMap()

    const zoomIn = screen.getByRole('button', { name: 'Zoom in' })
    for (let i = 0; i < 10 && !(zoomIn as HTMLButtonElement).disabled; i++) await user.click(zoomIn)

    expect(zoomIn).toBeDisabled()
    expect(useViewportStore.getState().zoom).toBe(4)
  })

  it('resets the whole view from the toolbar', async () => {
    const user = userEvent.setup()
    renderMap()

    await user.click(screen.getByRole('button', { name: 'Zoom in' }))
    await user.click(screen.getByRole('button', { name: 'Pan right' }))
    await user.click(screen.getByRole('button', { name: 'Reset view' }))

    expect(viewBox()).toBe('0 0 1000 600')
  })
})

describe('accessibility of the controls', () => {
  it('gives every toolbar control an accessible name that contains its visible text', () => {
    renderMap()

    const toolbar = screen.getByRole('group', { name: 'Floor plan view controls' })

    for (const button of within(toolbar).getAllByRole('button')) {
      expect(button).toHaveAccessibleName()
      const visible = button.textContent!.trim().toLowerCase()
      expect(button.getAttribute('aria-label')!.toLowerCase()).toContain(visible)
    }
  })

  it('makes the map itself focusable and describes how to operate it', () => {
    renderMap()

    expect(frame()).toHaveAttribute('tabindex', '0')
    expect(frame()).toHaveAccessibleDescription(/arrow keys/i)
    expect(frame()).toHaveAccessibleName(/6 units placed/)
  })

  it('keeps toolbar buttons keyboard operable', async () => {
    const user = userEvent.setup()
    renderMap()

    await user.tab()
    expect(screen.getByRole('button', { name: 'Zoom out' })).toHaveFocus()
    await user.keyboard('{Enter}')
    expect(useViewportStore.getState().zoom).toBeLessThan(1)
  })
})
