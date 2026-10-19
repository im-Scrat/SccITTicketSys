import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { AnnouncementRow } from '../types'
import { AnnouncementList } from './AnnouncementList'

function Where() {
  return <output data-testid="where">{useLocation().pathname}</output>
}

const ROW: AnnouncementRow = {
  id: 'ann-1',
  title: 'Network maintenance Friday',
  content: 'The Wi-Fi will be down from 6 pm.',
  pinned: true,
  at: '2026-10-17T02:00:00Z',
  href: '/app/announcements/ann-1',
}

const renderList = (rows: AnnouncementRow[]) =>
  render(
    <MemoryRouter initialEntries={['/app']}>
      <AnnouncementList rows={rows} />
      <Where />
    </MemoryRouter>,
  )

describe('AnnouncementList', () => {
  it('makes the title of a row with an href a real link', () => {
    renderList([ROW])

    expect(screen.getByRole('link', { name: 'Network maintenance Friday' })).toHaveAttribute(
      'href',
      '/app/announcements/ann-1',
    )
  })

  it('does not underline the title, hovered or not — the row highlight is the cue', () => {
    renderList([ROW])

    expect(screen.getByRole('link', { name: 'Network maintenance Friday' }).className).not.toMatch(
      /underline/,
    )
  })

  it('opens the announcement when the rest of the row is clicked', async () => {
    renderList([ROW])

    await userEvent.click(screen.getByText('The Wi-Fi will be down from 6 pm.'))

    expect(screen.getByTestId('where')).toHaveTextContent('/app/announcements/ann-1')
  })

  it('renders a row with no href as plain text, as before', async () => {
    renderList([{ ...ROW, href: undefined }])

    expect(screen.queryByRole('link')).toBeNull()
    await userEvent.click(screen.getByText('The Wi-Fi will be down from 6 pm.'))
    expect(screen.getByTestId('where')).toHaveTextContent('/app')
  })
})
