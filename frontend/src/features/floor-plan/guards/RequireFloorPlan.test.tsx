import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { RequireFloorPlan } from './RequireFloorPlan'

/**
 * The client-side reflection of "the floor plan is Administrator-only".
 *
 * These are UX assertions, not the security control: the API refuses everyone
 * but an Administrator independently. What they pin is that the guard asks for
 * the role **and** the permission, and fails closed — so a Technician or Teacher
 * who was individually granted `floorplan.view` still gets the Forbidden page.
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
        <RequireFloorPlan>
          <p>the floor plan</p>
        </RequireFloorPlan>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const forbidden = () => screen.getByRole('heading', { name: 'Access denied' })

describe('RequireFloorPlan', () => {
  it('lets an Administrator who holds floorplan.view through', () => {
    renderGuard(principal('administrator', ['floorplan.view', 'floorplan.manage']))

    expect(screen.getByText('the floor plan')).toBeInTheDocument()
  })

  it('refuses an Administrator whose permission was withdrawn', () => {
    renderGuard(principal('administrator', ['users.view']))

    expect(forbidden()).toBeInTheDocument()
    expect(screen.queryByText('the floor plan')).not.toBeInTheDocument()
  })

  it.each(['technician', 'teacher'])(
    'refuses a %s who was individually granted floorplan.view and floorplan.manage',
    (role) => {
      renderGuard(principal(role, ['floorplan.view', 'floorplan.manage']))

      expect(forbidden()).toBeInTheDocument()
      expect(screen.queryByText('the floor plan')).not.toBeInTheDocument()
    },
  )

  it.each(['technician', 'teacher'])('refuses a %s with no floor-plan permission', (role) => {
    renderGuard(principal(role, ['tickets.view']))

    expect(forbidden()).toBeInTheDocument()
  })

  it('fails closed when there is no signed-in user', () => {
    renderGuard(null)

    expect(forbidden()).toBeInTheDocument()
    expect(screen.queryByText('the floor plan')).not.toBeInTheDocument()
  })

  it('fails closed for an unknown role', () => {
    renderGuard(principal('auditor', ['floorplan.view']))

    expect(forbidden()).toBeInTheDocument()
  })
})
