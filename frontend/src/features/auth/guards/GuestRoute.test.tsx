import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import { rememberPendingScan } from '@/features/qr/lib/pendingScan'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { GuestRoute } from './GuestRoute'

/**
 * The guard that decides where authentication lands you (F-1).
 *
 * The defect these tests lock down was not that the destination was computed
 * wrongly — it was computed correctly and then overwritten, because both this
 * guard and `SignInPage` navigated. The guard now decides alone, so what needs
 * proving here is that it decides *correctly for every entry path*, and that an
 * unauthenticated visitor is still shown the guest page rather than redirected.
 *
 * These are client-side routing assertions. They are not the security control:
 * the API refuses independently, and `ProtectedRoute` plus the per-route role
 * and permission guards are unchanged by this fix.
 */

function principal(): AuthUser {
  return {
    id: 'uuid-1',
    first_name: 'Sam',
    middle_name: null,
    last_name: 'Reyes',
    name: 'Sam Reyes',
    email: 'sam@example.com',
    employee_number: null,
    contact_number: null,
    status: 'active',
    email_verified_at: null,
    must_reset_password: false,
    role: { id: 'role-1', name: 'Technician', slug: 'technician' },
    permissions: ['maintenance.view'],
  } as unknown as AuthUser
}

/**
 * Render the guard at /sign-in with landmark pages behind every destination the
 * fix can produce, so the assertion is "which page rendered", not "which
 * function was called".
 */
function renderGuard({ authenticated, state }: { authenticated: boolean; state?: unknown }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(AUTH_USER_KEY, authenticated ? principal() : null)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[{ pathname: '/sign-in', state }]}>
        <Routes>
          <Route element={<GuestRoute />}>
            <Route path="/sign-in" element={<p>sign-in form</p>} />
          </Route>
          <Route path="/app" element={<p>workspace</p>} />
          <Route path="/app/tickets/mine" element={<p>my tickets</p>} />
          <Route path="/app/qr/:code" element={<p>scanned panel</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  sessionStorage.clear()
})

describe('GuestRoute', () => {
  it('shows the guest page to an unauthenticated visitor', async () => {
    renderGuard({ authenticated: false })

    expect(await screen.findByText('sign-in form')).toBeInTheDocument()
  })

  it('sends an authenticated visitor with no intent to the workspace', async () => {
    renderGuard({ authenticated: true })

    expect(await screen.findByText('workspace')).toBeInTheDocument()
  })

  it('returns an authenticated visitor to the protected route that asked for sign-in', async () => {
    renderGuard({
      authenticated: true,
      state: { from: { pathname: '/app/tickets/mine', search: '' } },
    })

    expect(await screen.findByText('my tickets')).toBeInTheDocument()
  })

  it('resumes a scanned label rather than the workspace (FR-QR-011)', async () => {
    rememberPendingScan('PC-E2EFIXTURE')

    renderGuard({ authenticated: true })

    expect(await screen.findByText('scanned panel')).toBeInTheDocument()
  })

  it('lets the scanned label outrank the remembered route', async () => {
    rememberPendingScan('PC-E2EFIXTURE')

    renderGuard({
      authenticated: true,
      state: { from: { pathname: '/app/tickets/mine', search: '' } },
    })

    expect(await screen.findByText('scanned panel')).toBeInTheDocument()
  })

  it('never redirects off this origin, whatever router state claims', async () => {
    renderGuard({
      authenticated: true,
      state: { from: { pathname: 'https://evil.test/steal', search: '' } },
    })

    expect(await screen.findByText('workspace')).toBeInTheDocument()
  })

  it('consumes the scan, so it is spent once', async () => {
    rememberPendingScan('PC-E2EFIXTURE')

    const first = renderGuard({ authenticated: true })
    expect(await screen.findByText('scanned panel')).toBeInTheDocument()
    first.unmount()

    renderGuard({ authenticated: true })
    expect(await screen.findByText('workspace')).toBeInTheDocument()
  })
})
