import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { KpiItem } from '../types'
import { KpiRow } from './KpiRow'

const item = (key: string, extra: Partial<KpiItem> = {}): KpiItem => ({
  key,
  label: key,
  value: 1,
  ...extra,
})

const renderRow = (items: KpiItem[]) =>
  render(
    <MemoryRouter>
      <KpiRow items={items} />
    </MemoryRouter>,
  )

describe('KpiRow', () => {
  it('renders a figure with an href as a link, zero included', () => {
    renderRow([
      item('overdue', { label: 'Overdue', value: 0, href: '/app/tickets/assigned?view=overdue' }),
      item('plain', { label: 'Plain' }),
    ])

    expect(screen.getByRole('link', { name: 'Overdue: 0' })).toHaveAttribute(
      'href',
      '/app/tickets/assigned?view=overdue',
    )
    // No href, no link: it must not pretend to go somewhere.
    expect(screen.queryByRole('link', { name: /Plain/ })).toBeNull()
  })

  it('formats large values with separators', () => {
    renderRow([item('big', { label: 'Big', value: 12345, href: '/x' })])

    expect(screen.getByRole('link', { name: /Big: 12,345|Big: 12.345/ })).toBeInTheDocument()
  })

  it('sizes its columns by its own width and item count, not the viewport', () => {
    const { container, rerender } = renderRow([item('a'), item('b'), item('c')])

    // Three cards fill one row of three — never "two and one".
    expect(container.querySelector('ul')?.className).toContain('@md:grid-cols-3')
    expect(container.querySelector('.\\@container')).not.toBeNull()

    rerender(
      <MemoryRouter>
        <KpiRow items={[item('a'), item('b'), item('c'), item('d')]} />
      </MemoryRouter>,
    )
    expect(container.querySelector('ul')?.className).toContain('@2xl:grid-cols-4')
    // The old rule keyed on the viewport and clipped labels in a narrow aside.
    expect(container.querySelector('ul')?.className).not.toMatch(/(^|\s)lg:/)
  })
})
