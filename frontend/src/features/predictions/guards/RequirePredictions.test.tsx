import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { RequirePredictions } from './RequirePredictions'

/**
 * The client-side reflection of "predictive-maintenance findings are
 * Administrator-only" (WP-M). These are UX assertions, not the security
 * control: the API refuses everyone but an Administrator independently
 * (`PcPredictionAccess`). What this pins is that the guard asks for the role
 * **and** the permission, and fails closed — mirrors `RequireFloorPlan.test.tsx`.
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
        <RequirePredictions>
          <p>predictive maintenance</p>
        </RequirePredictions>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const forbidden = () => screen.getByRole('heading', { name: 'Access denied' })

describe('RequirePredictions', () => {
  it('lets an administrator who holds predictions.view through', () => {
    renderGuard(principal('administrator', ['predictions.view', 'predictions.manage']))

    expect(screen.getByText('predictive maintenance')).toBeInTheDocument()
  })

  it('refuses an administrator whose permission was withdrawn', () => {
    renderGuard(principal('administrator', ['users.view']))

    expect(forbidden()).toBeInTheDocument()
    expect(screen.queryByText('predictive maintenance')).not.toBeInTheDocument()
  })

  it.each(['technician', 'teacher'])(
    'refuses a %s who was individually granted predictions.view and predictions.manage',
    (role) => {
      renderGuard(principal(role, ['predictions.view', 'predictions.manage']))

      expect(forbidden()).toBeInTheDocument()
      expect(screen.queryByText('predictive maintenance')).not.toBeInTheDocument()
    },
  )

  it.each(['technician', 'teacher'])('refuses a %s with no predictions permission', (role) => {
    renderGuard(principal(role, ['tickets.view']))

    expect(forbidden()).toBeInTheDocument()
  })

  it('fails closed when there is no signed-in user', () => {
    renderGuard(null)

    expect(forbidden()).toBeInTheDocument()
    expect(screen.queryByText('predictive maintenance')).not.toBeInTheDocument()
  })

  it('fails closed for an unknown role', () => {
    renderGuard(principal('auditor', ['predictions.view']))

    expect(forbidden()).toBeInTheDocument()
  })
})
