import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'

/**
 * Route-level gating for the Maintenance module (WP-2.6).
 *
 * Maintenance is the **second** module whose permission does not separate the
 * roles — `maintenance.*` is seeded whole to Administrators *and* Technicians,
 * and which records each may reach is decided server-side by
 * `MaintenanceVisibility` (SDD DD-55). It differs from Tickets in one way that
 * matters here: a Teacher holds **no** maintenance permission at all, so the
 * route gate genuinely closes the module to them, and the row scope only has to
 * separate the two staff roles.
 *
 * These assert the client's half of the contract only. They are UX gating, not
 * security: the API independently refuses every one of these surfaces, which is
 * covered in the backend by MaintenanceVisibilityTest and
 * MaintenanceAuthorizationTest. A passing test here means the wrong page is not
 * *shown*, never that it could not be reached.
 */
function principal(
  overrides: Partial<AuthUser> & Pick<AuthUser, 'role' | 'permissions'>,
): AuthUser {
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
    last_login_at: null,
    email_verified_at: null,
    ...overrides,
  }
}

// The seeded baselines, kept in step with PermissionSeeder and SRS §8.4:
// maintenance is "all" for Administrator and Technician, and absent for Teacher.
const TEACHER = principal({
  role: { slug: 'teacher', name: 'Teacher' },
  permissions: ['tickets.view', 'tickets.create', 'tickets.comment', 'tickets.vote'],
})

const TECHNICIAN = principal({
  role: { slug: 'technician', name: 'Technician' },
  permissions: [
    'tickets.view',
    'tickets.update',
    'tickets.comment',
    'maintenance.view',
    'maintenance.create',
    'maintenance.update',
    'maintenance.delete',
    'maintenance.complete',
  ],
})

const ADMINISTRATOR = principal({
  role: { slug: 'administrator', name: 'Administrator' },
  permissions: [
    'tickets.view',
    'maintenance.view',
    'maintenance.create',
    'maintenance.update',
    'maintenance.delete',
    'maintenance.complete',
    'assets.view',
    'locations.view',
  ],
})

function renderAs(user: AuthUser | null, ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(AUTH_USER_KEY, user)

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const allowed = () => screen.queryByTestId('maintenance-surface')
const forbidden = () => screen.queryByRole('heading', { name: /access denied/i })

function Surface() {
  return <div data-testid="maintenance-surface">maintenance surface</div>
}

describe('maintenance route gating — the shared staff floor', () => {
  it.each([
    ['technician', TECHNICIAN],
    ['administrator', ADMINISTRATOR],
  ])('lets %s past the maintenance.view floor', (_label, user) => {
    renderAs(
      user,
      <RequirePermission permission="maintenance.view">
        <Surface />
      </RequirePermission>,
    )

    // Both staff roles hold the whole module by design; the separation is
    // row-level, not route-level. If this ever fails, the seeder and DD-55 have
    // diverged.
    expect(allowed()).toBeInTheDocument()
  })

  it('closes the whole module to a teacher', () => {
    // Unlike Tickets, the route gate really is the boundary here: a Teacher
    // holds no maintenance permission at all (SRS §8.4).
    renderAs(
      TEACHER,
      <RequirePermission permission="maintenance.view">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
    expect(forbidden()).toBeInTheDocument()
  })

  it('closes scheduling to a teacher', () => {
    renderAs(
      TEACHER,
      <RequirePermission permission="maintenance.create">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })

  it('lets a technician schedule their own work', () => {
    // Technician-initiated corrective maintenance is a first-class capability,
    // not a workaround (FR-MNT-009's WP-2.6 half).
    renderAs(
      TECHNICIAN,
      <RequirePermission permission="maintenance.create">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).toBeInTheDocument()
  })
})

describe('maintenance administration — gated by role, because no permission can', () => {
  it.each([
    ['teacher', TEACHER],
    ['technician', TECHNICIAN],
  ])('refuses %s the estate-wide surface', (_label, user) => {
    renderAs(
      user,
      <RequireRole roles={['administrator']}>
        <Surface />
      </RequireRole>,
    )

    expect(allowed()).not.toBeInTheDocument()
    expect(forbidden()).toBeInTheDocument()
  })

  it('admits an administrator', () => {
    renderAs(
      ADMINISTRATOR,
      <RequireRole roles={['administrator']}>
        <Surface />
      </RequireRole>,
    )

    expect(allowed()).toBeInTheDocument()
  })

  it('refuses a technician who holds the whole maintenance module but not the role', () => {
    // The trap this guard exists for. A technician holds every `maintenance.*`
    // permission there is — including `delete` — so no permission check could
    // close the estate view. Reassignment and the directory are the
    // administrator's by role (Client decision, 2026-08-28).
    expect(TECHNICIAN.permissions).toContain('maintenance.delete')
    expect(TECHNICIAN.permissions).toContain('maintenance.complete')

    renderAs(
      TECHNICIAN,
      <RequireRole roles={['administrator']}>
        <Surface />
      </RequireRole>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})

describe('technicians reach equipment only through their work', () => {
  it('shows a technician no Assets or Locations surface', () => {
    // The other half of DD-38/DD-28: a technician records maintenance against a
    // machine without any access to the register it lives in.
    expect(TECHNICIAN.permissions).not.toContain('assets.view')
    expect(TECHNICIAN.permissions).not.toContain('locations.view')

    renderAs(
      TECHNICIAN,
      <RequirePermission permission="assets.view">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})

describe('unauthenticated access', () => {
  it('shows no maintenance surface at all', () => {
    renderAs(
      null,
      <RequirePermission permission="maintenance.view">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})
