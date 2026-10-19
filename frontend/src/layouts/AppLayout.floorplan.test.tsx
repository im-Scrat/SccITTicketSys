import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { AppLayout } from './AppLayout'

// The bell talks to the API on mount; it is irrelevant to which links show.
vi.mock('@/features/notifications/components/NotificationMenu', () => ({
  NotificationMenu: () => null,
}))
// Phase 2.9 shell additions: no socket and no assistant in a nav test.
vi.mock('@/features/notifications/hooks/useUserChannel', () => ({
  useUserChannel: () => undefined,
}))
vi.mock('@/features/assistant/components/AssistantLauncher', () => ({
  AssistantLauncher: () => null,
}))

/**
 * Whether the navigation offers the floor plan. Hiding a link is not security
 * — the route guard and the API decide that — but a link that leads a Technician
 * to a Forbidden page is a broken promise, so it must track the same rule.
 */

function renderNav(role: string, permissions: string[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(AUTH_USER_KEY, {
    id: 'uuid-1',
    name: 'Sam Reyes',
    status: 'active',
    role: { name: role, slug: role },
    permissions,
  } as unknown as AuthUser)

  const view = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app']}>
        <AppLayout />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  // The navigation is a slide-out panel: open it, as a person would, so the
  // absent-link assertions below are about what the menu offers rather than
  // about the panel being closed.
  fireEvent.click(screen.getByRole('button', { name: /open navigation menu/i }))

  return view
}

describe('navigation: Floorplan', () => {
  it('is offered to an Administrator who holds floorplan.view', () => {
    renderNav('administrator', ['floorplan.view'])

    expect(screen.getByRole('link', { name: /Floorplan/ })).toHaveAttribute(
      'href',
      '/app/floor-plan',
    )
  })

  it('is not offered to an Administrator whose permission was withdrawn', () => {
    renderNav('administrator', ['users.view'])

    expect(screen.queryByRole('link', { name: /Floorplan/ })).not.toBeInTheDocument()
  })

  it.each(['technician', 'teacher'])(
    'is not offered to a %s, even one individually granted floorplan.view',
    (role) => {
      renderNav(role, ['floorplan.view', 'floorplan.manage'])

      expect(screen.queryByRole('link', { name: /Floorplan/ })).not.toBeInTheDocument()
    },
  )
})
