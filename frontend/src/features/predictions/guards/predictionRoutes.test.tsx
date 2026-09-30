import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { configure, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'

const fetchPredictions = vi.hoisted(() => vi.fn())
const fetchPrediction = vi.hoisted(() => vi.fn())

vi.mock('@/features/predictions/api/predictionsApi', () => ({
  fetchPredictions,
  fetchPrediction,
  confirmPrediction: vi.fn(),
  dismissPrediction: vi.fn(),
}))

// The bell polls the server; irrelevant to routing and noisy under jsdom.
vi.mock('@/features/notifications/components/NotificationMenu', () => ({
  NotificationMenu: () => null,
}))

/*
 * Every route in this SPA is lazy-loaded, so the first render after a cold
 * start waits on a dynamic import Vite has to transform. Testing Library's 1s
 * default is fine warm and flaky cold, and a flaky *guard* test is the worst
 * kind: a failure that gets re-run until it passes is one nobody reads.
 */
configure({ asyncUtilTimeout: 5000 })

/**
 * WP-M — predictive-maintenance findings are an Administrator's alone, asserted
 * against the **real routes in `App.tsx`**.
 *
 * `RequirePredictions.test.tsx` proves the guard component works; it cannot
 * notice if the route table is changed to stop using it. This suite renders
 * `<App/>` at the URL, so removing or loosening the gate on either route — or
 * the nav item — fails here.
 *
 * ── The trap being tested ──────────────────────────────────────────────────
 *
 * A permission can be granted to an individual (FR-USER-010). The technician and
 * teacher below are given **both** `predictions.view` and `predictions.manage`,
 * and must still be refused: the role is the fence, the permission only a floor.
 * UX gating only — the API refuses independently (`PcPredictionAuthorizationTest`)
 * — so what a pass here really proves is that the wrong page is not *shown* and
 * the API is never *called*, which would catch a page mounting before it is gated.
 */
function principal(role: AuthUser['role'], permissions: string[]): AuthUser {
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
    role,
    permissions,
  }
}

const ADMINISTRATOR = principal({ slug: 'administrator', name: 'Administrator' }, [
  'predictions.view',
  'predictions.manage',
  'tickets.view',
  'maintenance.view',
])
// Granted the module's permissions individually — the case that must not matter.
const TECHNICIAN = principal({ slug: 'technician', name: 'Technician' }, [
  'predictions.view',
  'predictions.manage',
  'tickets.view',
  'maintenance.view',
])
const TEACHER = principal({ slug: 'teacher', name: 'Teacher' }, [
  'predictions.view',
  'predictions.manage',
  'tickets.view',
  'tickets.create',
])

function renderAt(path: string, user: AuthUser | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(AUTH_USER_KEY, user)

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  fetchPredictions.mockReset()
  fetchPrediction.mockReset()

  fetchPredictions.mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null },
  })
  // Never resolves: the detail route only has to be shown to be admitted.
  fetchPrediction.mockReturnValue(new Promise(() => {}))
})

describe('the findings list route', () => {
  it('admits an administrator', async () => {
    renderAt('/app/predictions', ADMINISTRATOR)

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Predictive maintenance' }),
    ).toBeInTheDocument()
    expect(fetchPredictions).toHaveBeenCalled()
  })

  it.each([
    ['technician', TECHNICIAN],
    ['teacher', TEACHER],
  ])(
    'refuses a %s who holds predictions.view and .manage, and never calls the API',
    async (_name, user) => {
      expect(user.permissions).toEqual(
        expect.arrayContaining(['predictions.view', 'predictions.manage']),
      )

      renderAt('/app/predictions', user)

      expect(await screen.findByRole('heading', { name: /access denied/i })).toBeInTheDocument()
      expect(
        screen.queryByRole('heading', { name: 'Predictive maintenance' }),
      ).not.toBeInTheDocument()
      expect(fetchPredictions).not.toHaveBeenCalled()
    },
  )

  it('sends a signed-out visitor to sign in', async () => {
    renderAt('/app/predictions', null)

    expect(
      await screen.findByRole('heading', { name: /sign in to your workspace/i }),
    ).toBeInTheDocument()
    expect(fetchPredictions).not.toHaveBeenCalled()
  })

  it('refuses an administrator who has been denied predictions.view individually', async () => {
    renderAt('/app/predictions', {
      ...ADMINISTRATOR,
      permissions: ADMINISTRATOR.permissions.filter(
        (permission) => permission !== 'predictions.view',
      ),
    })

    expect(await screen.findByRole('heading', { name: /access denied/i })).toBeInTheDocument()
    expect(fetchPredictions).not.toHaveBeenCalled()
  })
})

describe('the single finding route', () => {
  it('admits an administrator and asks for that finding', async () => {
    renderAt('/app/predictions/pred-1', ADMINISTRATOR)

    await vi.waitFor(() => expect(fetchPrediction).toHaveBeenCalledWith('pred-1'))
    expect(screen.queryByRole('heading', { name: /access denied/i })).not.toBeInTheDocument()
  })

  it.each([
    ['technician', TECHNICIAN],
    ['teacher', TEACHER],
  ])('refuses a %s even with the finding’s exact address', async (_name, user) => {
    renderAt('/app/predictions/pred-1', user)

    expect(await screen.findByRole('heading', { name: /access denied/i })).toBeInTheDocument()
    expect(fetchPrediction).not.toHaveBeenCalled()
  })
})

describe('the navigation', () => {
  it('offers the surface to an administrator', async () => {
    renderAt('/app/announcements', ADMINISTRATOR)

    const nav = await screen.findByRole('navigation', { name: 'Primary' })

    expect(within(nav).getByRole('link', { name: /predictive maintenance/i })).toHaveAttribute(
      'href',
      '/app/predictions',
    )
  })

  it.each([
    ['technician', TECHNICIAN],
    ['teacher', TEACHER],
  ])('does not show it to a %s, even one granted the permission', async (_name, user) => {
    renderAt('/app/announcements', user)

    const nav = await screen.findByRole('navigation', { name: 'Primary' })

    expect(within(nav).queryByRole('link', { name: /predictive maintenance/i })).toBeNull()
  })
})
