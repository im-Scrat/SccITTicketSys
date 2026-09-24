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
 * Route-level gating for Ticket Management (Phase 2.6, WP-J).
 *
 * Ticket Management is the one module whose *permission* does not separate the
 * roles: `tickets.view` is seeded to all three, and which rows each may reach is
 * decided server-side by TicketVisibility (SDD DD-40). That makes the client
 * gating unusually easy to get wrong in a way no other module would surface —
 * a permission check that looks sufficient here is not.
 *
 * These assert the client's half of the contract only. They are UX gating, not
 * security: the API independently refuses every one of these surfaces, which is
 * covered live for all three roles by scripts/verify-ticket-roles.sh and in the
 * backend by TicketVisibilityTest and TicketApiAuthorizationTest. A passing test
 * here means the wrong page is not *shown*, never that it could not be reached.
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

// The seeded baselines, kept in step with PermissionSeeder. `tickets.assign`
// and `tickets.export` are deliberately absent from the technician: Phase 2.6
// withdrew both from the role baseline, leaving assign per-user grantable.
const TEACHER = principal({
  role: { slug: 'teacher', name: 'Teacher' },
  permissions: ['tickets.view', 'tickets.create', 'tickets.comment', 'tickets.vote'],
})

const TECHNICIAN = principal({
  role: { slug: 'technician', name: 'Technician' },
  permissions: ['tickets.view', 'tickets.update', 'tickets.comment'],
})

const ADMINISTRATOR = principal({
  role: { slug: 'administrator', name: 'Administrator' },
  permissions: [
    'tickets.view',
    'tickets.create',
    'tickets.update',
    'tickets.comment',
    'tickets.vote',
    'tickets.assign',
    'tickets.export',
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

const allowed = () => screen.queryByTestId('ticket-surface')
const forbidden = () => screen.queryByRole('heading', { name: /access denied/i })

function Surface() {
  return <div data-testid="ticket-surface">ticket surface</div>
}

describe('ticket route gating — the shared-permission floor', () => {
  it.each([
    ['teacher', TEACHER],
    ['technician', TECHNICIAN],
    ['administrator', ADMINISTRATOR],
  ])('lets %s past the tickets.view floor', (_label, user) => {
    renderAs(
      user,
      <RequirePermission permission="tickets.view">
        <Surface />
      </RequirePermission>,
    )

    // All three hold tickets.view by design; the separation is row-level, not
    // route-level. If this ever fails, the seeder and DD-40 have diverged.
    expect(allowed()).toBeInTheDocument()
  })

  it('keeps a teacher out of the technician queue', () => {
    renderAs(
      TEACHER,
      <RequirePermission permission="tickets.update">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
    expect(forbidden()).toBeInTheDocument()
  })

  it('keeps a technician out of ticket creation', () => {
    // A technician works what they are given; they do not report faults.
    renderAs(
      TECHNICIAN,
      <RequirePermission permission="tickets.create">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})

describe('ticket administration — gated by role, because no permission can', () => {
  it.each([
    ['teacher', TEACHER],
    ['technician', TECHNICIAN],
  ])('refuses %s the administrative surface', (_label, user) => {
    renderAs(
      user,
      <RequireRole roles={['administrator']}>
        <Surface />
      </RequireRole>,
    )

    // `tickets.view` cannot close this surface — every role holds it — so the
    // route gates on role and each endpoint re-checks viewAdministrative.
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

  it('refuses a technician who holds tickets.update but not the role', () => {
    // The trap this guard exists for: a permission that looks administrative
    // enough. It is not — assignment authority is the administrator's, and
    // tickets.assign was withdrawn from the technician baseline in Phase 2.6.
    expect(TECHNICIAN.permissions).not.toContain('tickets.assign')
    expect(TECHNICIAN.permissions).not.toContain('tickets.export')

    renderAs(
      TECHNICIAN,
      <RequireRole roles={['administrator']}>
        <Surface />
      </RequireRole>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})

describe('unauthenticated access', () => {
  it('shows no ticket surface at all', () => {
    renderAs(
      null,
      <RequirePermission permission="tickets.view">
        <Surface />
      </RequirePermission>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})
