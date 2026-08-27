import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { DistributionMeter } from './DistributionMeter'

const rows = [
  { key: 'open', label: 'Open', count: 12 },
  { key: 'in-progress', label: 'In Progress', count: 5 },
  { key: 'on-hold', label: 'On Hold', count: 0 },
]

describe('DistributionMeter', () => {
  it('renders every category and count as real text, not just bars', () => {
    render(<DistributionMeter rows={rows} unit="tickets" />)

    // Each label is a row header, so the reading order is label → value.
    expect(screen.getByRole('rowheader', { name: 'Open' })).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: 'In Progress' })).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: 'On Hold' })).toBeInTheDocument()

    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('5')).toBeInTheDocument()
    expect(screen.getByText('0')).toBeInTheDocument()
  })

  it('is a table, so the accessible reading and the visual reading are one object', () => {
    render(<DistributionMeter rows={rows} unit="tickets" />)

    expect(screen.getByRole('table')).toBeInTheDocument()
    // Header row is present for assistive technology even though it is visually hidden.
    expect(screen.getByRole('columnheader', { name: 'Count' })).toBeInTheDocument()
  })

  it('states the total so the part-to-whole reading is still available', () => {
    render(<DistributionMeter rows={rows} unit="tickets" />)

    expect(screen.getByText('17 tickets in total')).toBeInTheDocument()
  })

  it('teaches instead of showing an empty frame when there is no data', () => {
    render(<DistributionMeter rows={[]} emptyLabel="No tickets have been raised yet." />)

    expect(screen.getByText('No tickets have been raised yet.')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('treats an all-zero distribution as empty rather than drawing flat bars', () => {
    render(
      <DistributionMeter
        rows={[{ key: 'open', label: 'Open', count: 0 }]}
        emptyLabel="Nothing open."
      />,
    )

    expect(screen.getByText('Nothing open.')).toBeInTheDocument()
  })
})
