import { fireEvent, render, screen } from '@testing-library/react'
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard'

function Editor({ dirty }: { dirty: boolean }) {
  const guard = useUnsavedChangesGuard(dirty)
  return (
    <>
      <Link to="/elsewhere">Tickets</Link>
      {guard.pending && (
        <div role="dialog" aria-label="Leave?">
          <button type="button" onClick={guard.leave}>
            Leave
          </button>
          <button type="button" onClick={guard.stay}>
            Stay
          </button>
        </div>
      )}
    </>
  )
}

function renderGuard(dirty: boolean) {
  render(
    <MemoryRouter initialEntries={['/editor']}>
      <Routes>
        <Route path="/editor" element={<Editor dirty={dirty} />} />
        <Route path="/elsewhere" element={<h1>Elsewhere</h1>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('the unsaved-changes guard', () => {
  it('lets a link through when there is nothing to lose', () => {
    renderGuard(false)
    fireEvent.click(screen.getByRole('link', { name: 'Tickets' }))
    expect(screen.getByRole('heading', { name: 'Elsewhere' })).toBeInTheDocument()
  })

  it('stops an in-app link and asks first when there are unsaved changes', () => {
    renderGuard(true)
    fireEvent.click(screen.getByRole('link', { name: 'Tickets' }))

    expect(screen.getByRole('dialog', { name: 'Leave?' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Elsewhere' })).not.toBeInTheDocument()
  })

  it('goes where the link pointed once the administrator chooses to leave', () => {
    renderGuard(true)
    fireEvent.click(screen.getByRole('link', { name: 'Tickets' }))
    fireEvent.click(screen.getByRole('button', { name: 'Leave' }))

    expect(screen.getByRole('heading', { name: 'Elsewhere' })).toBeInTheDocument()
  })

  it('stays put when asked to', () => {
    renderGuard(true)
    fireEvent.click(screen.getByRole('link', { name: 'Tickets' }))
    fireEvent.click(screen.getByRole('button', { name: 'Stay' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Elsewhere' })).not.toBeInTheDocument()
  })

  it('asks the browser to confirm closing or reloading the tab', () => {
    renderGuard(true)
    const event = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
  })
})
