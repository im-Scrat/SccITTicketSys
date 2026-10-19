import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LinkedFilterChips } from './LinkedFilterChips'

vi.mock('@/features/analytics/hooks/useReportsAccess', () => ({
  useReportsAccess: () => ({ canView: true, canExport: true }),
}))
vi.mock('@/features/analytics/hooks/queries', () => ({
  useReportOptions: () => ({
    data: {
      rooms: [{ id: 'room-1', label: 'Main · Ground · Lab 2' }],
      buildings: [],
    },
  }),
}))

describe('LinkedFilterChips', () => {
  it('names every filter a report brought, each removable on its own', () => {
    const patch = vi.fn()
    render(
      <LinkedFilterChips
        params={
          new URLSearchParams(
            'reported_from=2026-10-01&reported_to=2026-10-18&room=room-1&exclude_duplicates=1&exclude_cancelled=1',
          )
        }
        patch={patch}
      />,
    )

    expect(screen.getByText('Reported 2026-10-01 to 2026-10-18')).toBeInTheDocument()
    expect(screen.getByText('Room: Main · Ground · Lab 2')).toBeInTheDocument()

    fireEvent.click(
      screen.getByRole('button', {
        name: 'Remove filter: Problems only (no duplicates, nothing cancelled)',
      }),
    )

    expect(patch).toHaveBeenCalledWith({
      exclude_duplicates: null,
      exclude_cancelled: null,
      page: null,
    })
  })

  it('renders nothing when no report filter is in the URL', () => {
    const { container } = render(
      <LinkedFilterChips params={new URLSearchParams('status=open')} patch={vi.fn()} />,
    )

    expect(container).toBeEmptyDOMElement()
  })
})
