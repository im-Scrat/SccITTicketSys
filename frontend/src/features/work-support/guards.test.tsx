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
 * Route-level gating for Work Support (FR-WSR-009/010).
 *
 * The third module whose permission does not separate the roles: `maintenance.*`
 * is seeded whole to Administrators *and* Technicians, and no `wsr.*` permission
 * exists — Client decision OD-4 deliberately declined to invent one, so the
 * §8.4 matrix is unchanged. The role is therefore what separates the two
 * surfaces.
 *
 * ── The two surfaces are not two views of one page ─────────────────────────
 *
 * **"My submissions"** is the technician's record of what *they* submitted.
 * **"Support requests"** is the administrator's inbox of what others sent them.
 * They are opposite surfaces, not a shared page with a role-dependent scope,
 * which is why each role reaches exactly one of them (Client decision,
 * 2026-08-30). An administrator gets Forbidden on the tracking page rather than
 * an empty personal history.
 *
 * ── What these tests are, and are not ──────────────────────────────────────
 *
 * The client's half of the contract only — UX gating, not security. The API
 * refuses independently, and in the strongest available way: `GET
 * /api/technician/submissions` takes no identifier and reads the session, so
 * "someone else's submissions" is not a request it can express. A passing test
 * here means the wrong page is not *shown*, never that data could leak.
 * `WorkSupportAuthorizationTest` covers the server side.
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

const allowed = () => screen.queryByTestId('work-support-surface')
const forbidden = () => screen.queryByRole('heading', { name: /access denied/i })

function Surface() {
  return <div data-testid="work-support-surface">work support surface</div>
}

/** The tracking page's gate, exactly as `App.tsx` composes it. */
function TrackingRoute({ children }: { children: ReactNode }) {
  return (
    <RequirePermission permission="maintenance.view">
      <RequireRole roles={['technician']}>{children}</RequireRole>
    </RequirePermission>
  )
}

/** The inbox's gate, exactly as `App.tsx` composes it. */
function InboxRoute({ children }: { children: ReactNode }) {
  return <RequireRole roles={['administrator']}>{children}</RequireRole>
}

describe('My submissions — technician-only (FR-WSR-009)', () => {
  it('admits a technician', () => {
    renderAs(
      TECHNICIAN,
      <TrackingRoute>
        <Surface />
      </TrackingRoute>,
    )

    expect(allowed()).toBeInTheDocument()
  })

  it('refuses an administrator, who holds the floor but not the role', () => {
    // The defect this guard exists for. An administrator is seeded every
    // permission in the system, so `maintenance.view` cannot close this page —
    // only the role can. Before the 2026-08-30 correction the item appeared in
    // their navigation and the route opened to an empty personal history.
    expect(ADMINISTRATOR.permissions).toContain('maintenance.view')

    renderAs(
      ADMINISTRATOR,
      <TrackingRoute>
        <Surface />
      </TrackingRoute>,
    )

    expect(allowed()).not.toBeInTheDocument()
    expect(forbidden()).toBeInTheDocument()
  })

  it('refuses a teacher at the floor, before the role is even consulted', () => {
    // A Teacher holds no maintenance permission at all, so the outer guard
    // closes this without the role mattering (SRS §8.4).
    expect(TEACHER.permissions).not.toContain('maintenance.view')

    renderAs(
      TEACHER,
      <TrackingRoute>
        <Surface />
      </TrackingRoute>,
    )

    expect(allowed()).not.toBeInTheDocument()
    expect(forbidden()).toBeInTheDocument()
  })

  it('shows nothing to an unauthenticated visitor', () => {
    renderAs(
      null,
      <TrackingRoute>
        <Surface />
      </TrackingRoute>,
    )

    expect(allowed()).not.toBeInTheDocument()
  })
})

describe('Support requests — the administrator inbox, unchanged (FR-WSR-010)', () => {
  it('admits an administrator', () => {
    // Asserted here so the correction above cannot quietly take the inbox with
    // it: the two surfaces move in opposite directions by design.
    renderAs(
      ADMINISTRATOR,
      <InboxRoute>
        <Surface />
      </InboxRoute>,
    )

    expect(allowed()).toBeInTheDocument()
  })

  it.each([
    ['teacher', TEACHER],
    ['technician', TECHNICIAN],
  ])('refuses %s the inbox', (_label, user) => {
    renderAs(
      user,
      <InboxRoute>
        <Surface />
      </InboxRoute>,
    )

    expect(allowed()).not.toBeInTheDocument()
    expect(forbidden()).toBeInTheDocument()
  })
})

describe('the two surfaces are disjoint', () => {
  it('gives each staff role exactly one work-support surface', () => {
    // The whole of the Client's 2026-08-30 decision in one assertion: neither
    // role reaches both pages, and neither reaches none.
    const reaches = (user: AuthUser) => {
      const { unmount: unmountTracking } = renderAs(
        user,
        <TrackingRoute>
          <Surface />
        </TrackingRoute>,
      )
      const tracking = allowed() !== null
      unmountTracking()

      const { unmount: unmountInbox } = renderAs(
        user,
        <InboxRoute>
          <Surface />
        </InboxRoute>,
      )
      const inbox = allowed() !== null
      unmountInbox()

      return { tracking, inbox }
    }

    expect(reaches(TECHNICIAN)).toEqual({ tracking: true, inbox: false })
    expect(reaches(ADMINISTRATOR)).toEqual({ tracking: false, inbox: true })
  })
})
