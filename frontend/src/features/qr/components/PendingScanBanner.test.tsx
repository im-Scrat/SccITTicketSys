import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import { rememberPendingScan } from '../lib/pendingScan'
import { PendingScanBanner } from './PendingScanBanner'

function renderBanner() {
  render(
    <MemoryRouter initialEntries={['/app']}>
      <Routes>
        <Route path="/app" element={<PendingScanBanner />} />
        <Route path="/app/qr/:code" element={<p>scanned panel</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  sessionStorage.clear()
})

describe('PendingScanBanner', () => {
  it('shows nothing when no label was scanned', () => {
    renderBanner()

    expect(screen.queryByRole('region', { name: 'The PC you scanned' })).not.toBeInTheDocument()
  })

  it('offers the scanned PC and opens it, spending the scan', async () => {
    rememberPendingScan('PC-LAB4-01')
    renderBanner()

    await userEvent.click(screen.getByRole('button', { name: 'Continue to the PC you scanned' }))

    expect(await screen.findByText('scanned panel')).toBeInTheDocument()
    expect(sessionStorage.getItem('sccit.pendingScan')).toBeNull()
  })

  it('can be dismissed, which also spends the scan', async () => {
    rememberPendingScan('PC-LAB4-01')
    renderBanner()

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss the scanned PC' }))

    expect(screen.queryByRole('region', { name: 'The PC you scanned' })).not.toBeInTheDocument()
    expect(sessionStorage.getItem('sccit.pendingScan')).toBeNull()
  })

  it('ignores a tampered stored value that is not a code', () => {
    sessionStorage.setItem('sccit.pendingScan', 'https://evil.test')
    renderBanner()

    expect(screen.queryByRole('region', { name: 'The PC you scanned' })).not.toBeInTheDocument()
  })
})
