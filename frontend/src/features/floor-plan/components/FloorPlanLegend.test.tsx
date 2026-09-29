import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { pc } from '../test/fixtures'
import { FloorPlanLegend } from './FloorPlanLegend'
import { PcUnitList } from './PcUnitList'

describe('FloorPlanLegend', () => {
  it('lists each status on the map once, with its label and how many units carry it', () => {
    render(
      <FloorPlanLegend
        pcs={[pc(1, 'online'), pc(2, 'online'), pc(3, 'offline'), pc(4, 'under_maintenance')]}
      />,
    )

    const legend = screen.getByRole('region', { name: 'Legend' })
    const items = within(legend).getAllByRole('listitem')

    expect(items).toHaveLength(3)
    expect(within(legend).getByText('Online')).toBeInTheDocument()
    expect(within(legend).getByText('2 units')).toBeInTheDocument()
    expect(within(legend).getByText('Offline')).toBeInTheDocument()
    expect(within(legend).getByText('Under Maintenance')).toBeInTheDocument()
    expect(within(legend).getAllByText('1 unit')).toHaveLength(2)
  })

  it('takes its wording from the server payload rather than a list of its own', () => {
    render(
      <FloorPlanLegend
        pcs={[
          pc(1, 'online', {
            status: { value: 'online', label: 'Up and running', tone: 'success' },
          }),
        ]}
      />,
    )

    expect(screen.getByText('Up and running')).toBeInTheDocument()
    expect(screen.queryByText('Online')).not.toBeInTheDocument()
  })

  it('hides the shape swatches from assistive technology — the text is the content', () => {
    const { container } = render(<FloorPlanLegend pcs={[pc(1, 'available')]} />)

    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
  })

  it('renders nothing when the room has no placed units', () => {
    const { container } = render(<FloorPlanLegend pcs={[]} />)

    expect(container).toBeEmptyDOMElement()
  })
})

describe("PcUnitList (the map's text alternative)", () => {
  it('lists every placed unit with its status in words and its position', () => {
    render(
      <PcUnitList
        roomName="Computer Lab 2"
        pcs={[pc(1, 'online', { x: 100, y: 120 }), pc(2, 'offline', { x: 200, y: 120 })]}
      />,
    )

    const table = screen.getByRole('table', { name: 'Units placed in Computer Lab 2' })
    const rows = within(table).getAllByRole('row')

    // Header + two units.
    expect(rows).toHaveLength(3)
    expect(within(table).getByRole('columnheader', { name: 'Status' })).toBeInTheDocument()
    expect(within(rows[1]).getByText('PC-01')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Online')).toBeInTheDocument()
    expect(within(rows[1]).getByText('100, 120')).toBeInTheDocument()
    expect(within(rows[2]).getByText('Offline')).toBeInTheDocument()
  })

  it('renders nothing for an empty room', () => {
    const { container } = render(<PcUnitList roomName="Empty" pcs={[]} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('offers no details column when the caller gives no onInspect (WP-G)', () => {
    render(<PcUnitList roomName="Computer Lab 2" pcs={[pc(1, 'online')]} />)

    expect(screen.queryByRole('columnheader', { name: 'Details' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /View details/ })).not.toBeInTheDocument()
  })

  it('offers a details button per row that reports which unit was chosen (WP-G)', () => {
    const onInspect = vi.fn()
    render(
      <PcUnitList
        roomName="Computer Lab 2"
        pcs={[pc(1, 'online'), pc(2, 'offline')]}
        onInspect={onInspect}
      />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'View details for PC-02' }))

    expect(onInspect).toHaveBeenCalledExactlyOnceWith('pc-uuid-2')
  })
})
