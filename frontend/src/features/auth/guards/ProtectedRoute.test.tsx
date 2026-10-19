import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import ForcePasswordChangePage from '@/features/auth/pages/ForcePasswordChangePage'
import type { AuthUser } from '@/features/auth/types'
import { ProtectedRoute } from './ProtectedRoute'

/**
 * The forced-password-reset gate (pre-commit fix, Phase 2.9).
 *
 * The defect: a technician under an administrator's forced reset reached every
 * page, and every page failed with its own error, because the server refuses
 * all `password.current` routes with 403 `password_reset_required` and nothing
 * on the client read the flag. These tests prove the client now routes on it.
 * They are navigation assertions — the middleware remains the security control.
 */

const authApi = vi.hoisted(() => ({
  changePassword: vi.fn(),
  fetchCurrentUser: vi.fn(),
  logout: vi.fn(),
}))

vi.mock('@/features/auth/api/authApi', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  ...authApi,
}))

function principal(forced: boolean): AuthUser {
  return {
    id: 'uuid-7',
    first_name: 'Tess',
    middle_name: null,
    last_name: 'Ko',
    name: 'Tess Ko',
    email: 'tess@example.com',
    status: 'active',
    force_password_reset: forced,
    role: { id: 'role-2', name: 'Technician', slug: 'technician' },
    permissions: ['maintenance.view'],
  } as unknown as AuthUser
}

function Here({ label }: { label: string }) {
  const { search } = useLocation()
  return (
    <p>
      {label}
      {search}
    </p>
  )
}

function renderAt(entry: string | { pathname: string; state: unknown }, forced: boolean) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(AUTH_USER_KEY, principal(forced))

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route element={<ProtectedRoute />}>
            <Route path="/change-password" element={<ForcePasswordChangePage />} />
            <Route path="/app" element={<p>dashboard</p>} />
            <Route path="/app/work" element={<Here label="my work" />} />
            <Route path="/app/qr/:code" element={<Here label="scan panel" />} />
          </Route>
          <Route path="/sign-in" element={<p>sign-in form</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return queryClient
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('ProtectedRoute — forced password reset', () => {
  it('lets an unflagged user into the workspace', async () => {
    renderAt('/app/work', false)

    expect(await screen.findByText('my work')).toBeInTheDocument()
  })

  it('sends a flagged user from any workspace page to the change screen', async () => {
    renderAt('/app/work', true)

    expect(await screen.findByRole('heading', { name: 'Change your password' })).toBeInTheDocument()
    expect(screen.queryByText('my work')).not.toBeInTheDocument()
  })

  it('sends an unflagged user away from the change screen', async () => {
    renderAt('/change-password', false)

    expect(await screen.findByText('dashboard')).toBeInTheDocument()
  })

  it('clears the gate after a successful change and returns to the page asked for', async () => {
    authApi.changePassword.mockResolvedValue({ message: 'ok' })
    authApi.fetchCurrentUser.mockResolvedValue(principal(false))
    const user = userEvent.setup()

    renderAt('/app/work', true)

    await user.type(await screen.findByLabelText(/^current password/i), 'OldPassw0rd!')
    await user.type(screen.getByLabelText(/^new password/i), 'N3w-Passw0rd!')
    await user.type(screen.getByLabelText(/^confirm new password/i), 'N3w-Passw0rd!')
    await user.click(screen.getByRole('button', { name: /set new password and continue/i }))

    expect(await screen.findByText('my work')).toBeInTheDocument()
    expect(authApi.changePassword).toHaveBeenCalledOnce()
    // The principal was re-read from the server rather than patched locally.
    expect(authApi.fetchCurrentUser).toHaveBeenCalled()
  })

  it('keeps the user on the change screen when the server rejects the change', async () => {
    authApi.changePassword.mockRejectedValue(new Error('network'))
    const user = userEvent.setup()

    renderAt('/app/work', true)

    await user.type(await screen.findByLabelText(/^current password/i), 'OldPassw0rd!')
    await user.type(screen.getByLabelText(/^new password/i), 'N3w-Passw0rd!')
    await user.type(screen.getByLabelText(/^confirm new password/i), 'N3w-Passw0rd!')
    await user.click(screen.getByRole('button', { name: /set new password and continue/i }))

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.queryByText('my work')).not.toBeInTheDocument()
  })

  it('returns to a resumed scan with its query string after the change (FR-QR-011)', async () => {
    renderAt(
      {
        pathname: '/change-password',
        state: { from: { pathname: '/app/qr/PC-AB12CD34EF', search: '?from=scan' } },
      },
      false,
    )

    expect(await screen.findByText('scan panel?from=scan')).toBeInTheDocument()
  })

  it.each([
    ['protocol-relative', '//evil.test/app'],
    // Inside the workspace prefix, so only the shared validator can stop it.
    ['a backslash inside the workspace', '/app/\\evil.test'],
    ['absolute URL', 'https://evil.test/app'],
    ['outside the workspace', '/sign-in'],
    ['a look-alike prefix', '/application'],
  ])('falls back to the workspace for an unsafe return path (%s)', async (_, pathname) => {
    renderAt({ pathname: '/change-password', state: { from: { pathname } } }, false)

    expect(await screen.findByText('dashboard')).toBeInTheDocument()
  })
})
