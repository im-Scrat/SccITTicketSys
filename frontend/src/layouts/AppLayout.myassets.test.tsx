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
 * Whether the navigation offers "My assets" (Phase 2.9). Custody is only ever
 * held by Technicians and Administrators, so a Teacher has no asset surface: the
 * link is absent, the route renders Forbidden, and `/my/assets` answers 403.
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

describe('navigation: My assets', () => {
  it.each(['technician', 'administrator'])('is offered to a %s', (role) => {
    renderNav(role, ['tickets.view'])

    expect(screen.getByRole('link', { name: /My assets/ })).toHaveAttribute(
      'href',
      '/app/my-assets',
    )
  })

  it('is not offered to a teacher', () => {
    renderNav('teacher', ['tickets.view', 'tickets.create', 'tickets.comment', 'tickets.vote'])

    expect(screen.queryByRole('link', { name: /My assets/ })).not.toBeInTheDocument()
  })
})
