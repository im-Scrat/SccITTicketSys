import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { RequireAssetImport } from './RequireAssetImport'

/**
 * The client-side reflection of "the asset import is Administrator-only"
 * (Phase 2.10 R6). UX assertions, not the security control — the API refuses
 * everyone else independently (`AssetImportAccess`). What this pins is that
 * the guard asks for the role **and** both permissions, and fails closed.
 */

function principal(role: string, permissions: string[]): AuthUser {
  return {
    id: 'uuid-1',
    name: 'Sam Reyes',
    status: 'active',
    role: { name: role, slug: role },
    permissions,
  } as unknown as AuthUser
}

function renderGuard(user: AuthUser | null) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(AUTH_USER_KEY, user)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <RequireAssetImport>
          <p>import wizard</p>
        </RequireAssetImport>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const forbidden = () => screen.getByRole('heading', { name: 'Access denied' })

describe('RequireAssetImport', () => {
  it('lets an administrator who may view and create assets through', () => {
    renderGuard(principal('administrator', ['assets.view', 'assets.create']))

    expect(screen.getByText('import wizard')).toBeInTheDocument()
  })

  it('refuses an administrator whose assets.create was withdrawn', () => {
    renderGuard(principal('administrator', ['assets.view']))

    expect(forbidden()).toBeInTheDocument()
    expect(screen.queryByText('import wizard')).not.toBeInTheDocument()
  })

  it.each(['technician', 'teacher'])(
    'refuses a %s granted assets.view and assets.create by hand',
    (role) => {
      renderGuard(principal(role, ['assets.view', 'assets.create']))

      expect(forbidden()).toBeInTheDocument()
      expect(screen.queryByText('import wizard')).not.toBeInTheDocument()
    },
  )
})
