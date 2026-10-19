import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { DonutChart } from './DonutChart'

const slices = [
  { key: 'open', label: 'Open', count: 3 },
  { key: 'resolved', label: 'Resolved', count: 2 },
]

describe('DonutChart', () => {
  it('is one named image for assistive technology', () => {
    render(<DonutChart slices={slices} size={160} centreLabel="tickets" label="5 tickets" />)
    expect(screen.getByRole('img', { name: '5 tickets' })).toBeInTheDocument()
  })

  // axe aria-hidden-focus on the admin dashboard (Phase 2.11): Recharts gives
  // the Pie's root layer tabIndex 0 unless told otherwise.
  it('puts nothing inside the hidden drawing in the tab order', () => {
    const { container } = render(
      <DonutChart slices={slices} size={160} centreLabel="tickets" label="5 tickets" />,
    )
    const hidden = container.querySelectorAll('[aria-hidden="true"]')
    expect(hidden.length).toBeGreaterThan(0)
    expect(container.querySelector('svg')).not.toBeNull()
    for (const region of hidden) {
      const tabbable = [...region.querySelectorAll('[tabindex]')].filter(
        (el) => Number(el.getAttribute('tabindex')) >= 0,
      )
      expect(tabbable).toEqual([])
    }
  })
})
