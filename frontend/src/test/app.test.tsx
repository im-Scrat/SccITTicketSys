import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import App from '@/App'
import { queryClient } from '@/services/queryClient'

function renderAt(path: string) {
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('public routing', () => {
  it('renders the landing hero and sections on /', async () => {
    renderAt('/')
    expect(
      await screen.findByRole('heading', { level: 1, name: /operations console/i }),
    ).toBeInTheDocument()
    // A later section renders too (exercises the whole page tree).
    expect(await screen.findByText(/Enterprise security, not an afterthought/i)).toBeInTheDocument()
    // Primary CTA is present.
    expect(screen.getAllByRole('link', { name: /book a demo/i }).length).toBeGreaterThan(0)
  })

  it('renders the sign-in entry on /sign-in', async () => {
    renderAt('/sign-in')
    expect(
      await screen.findByRole('heading', { name: /sign in to your workspace/i }),
    ).toBeInTheDocument()
  })

  it('renders a 404 for an unknown route', async () => {
    renderAt('/does-not-exist')
    expect(
      await screen.findByRole('heading', { name: /this page doesn’t exist/i }),
    ).toBeInTheDocument()
  })
})
