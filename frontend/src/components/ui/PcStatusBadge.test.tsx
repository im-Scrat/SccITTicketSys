import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PcStatusBadge } from './PcStatusBadge'

describe('PcStatusBadge', () => {
  it.each([
    ['available', 'Available', 'text-success-strong'],
    ['pending_repair', 'Pending Repair', 'text-warning-strong'],
    ['needs_fix', 'Needs Fix', 'text-danger-strong'],
    ['unassigned', 'Unassigned', 'text-muted'],
  ])('renders %s as a labelled badge with an icon', (status, label, hue) => {
    const { container } = render(<PcStatusBadge status={status} />)

    const badge = screen.getByText(label)
    expect(badge.className).toContain(hue)
    // The icon is decoration beside the words, hidden from assistive technology.
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
  })

  it('renders an unknown value plainly rather than inventing a colour for it', () => {
    render(<PcStatusBadge status="online" />)

    const badge = screen.getByText('Online')
    expect(badge.className).toContain('text-muted')
  })
})
