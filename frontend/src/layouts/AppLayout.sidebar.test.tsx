import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { AUTH_USER_KEY } from '@/features/auth/hooks/useAuth'
import type { AuthUser } from '@/features/auth/types'
import { AppLayout } from './AppLayout'

vi.mock('@/features/notifications/components/NotificationMenu', () => ({
  NotificationMenu: () => null,
}))
vi.mock('@/features/notifications/hooks/useUserChannel', () => ({
  useUserChannel: () => undefined,
}))
vi.mock('@/features/assistant/components/AssistantLauncher', () => ({
  AssistantLauncher: () => null,
}))

/**
 * The slide-out navigation shell. What is asserted here is behaviour a person
 * relies on — open, close three ways, keep their place, be handed only their own
 * role's links — not the CSS that animates it (jsdom has no layout or paint).
 * Which items each role is *entitled* to is `navConfig.test.ts`; this file
 * checks that the shell renders exactly that and nothing more.
 */
function renderShell(role: string, permissions: string[], path = '/app') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  queryClient.setQueryData(AUTH_USER_KEY, {
    id: 'uuid-1',
    first_name: 'Sam',
    name: 'Sam Reyes',
    status: 'active',
    role: { name: role, slug: role },
    permissions,
  } as unknown as AuthUser)

  const user = userEvent.setup()

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/app" element={<AppLayout />}>
            <Route index element={<h1>Home page</h1>} />
            <Route path="announcements" element={<h1>Announcements page</h1>} />
            <Route path="tickets/mine" element={<h1>My tickets page</h1>} />
            <Route path="tickets/manage" element={<h1>Ticket management page</h1>} />
            <Route path="*" element={<h1>Elsewhere</h1>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return user
}

const openButton = () => screen.getByRole('button', { name: /open navigation menu/i })
const closeButton = () => screen.getByRole('button', { name: /close navigation menu/i })
const sidebar = () => document.getElementById('app-sidebar') as HTMLElement
const ADMIN_PERMISSIONS = ['tickets.view', 'users.view', 'maintenance.view', 'assets.view']

describe('the menu button', () => {
  it.each(['administrator', 'technician', 'teacher'])(
    'is a labelled button on the %s shell, collapsed to begin with',
    (role) => {
      renderShell(role, [])

      const button = openButton()
      expect(button.tagName).toBe('BUTTON')
      expect(button).toHaveAttribute('aria-expanded', 'false')
      expect(button).toHaveAttribute('aria-controls', 'app-sidebar')
      expect(sidebar()).toBeInTheDocument()
    },
  )

  it('starts with the panel closed: out of the tab order and the page still live', () => {
    renderShell('administrator', ADMIN_PERMISSIONS)

    expect(sidebar()).toHaveAttribute('inert')
    expect(screen.getByRole('heading', { name: 'Home page' }).closest('[inert]')).toBeNull()
  })
})

describe('opening and closing', () => {
  it('opens on click: the button turns into a close control and the page behind goes inert', async () => {
    const user = renderShell('administrator', ADMIN_PERMISSIONS)

    await user.click(openButton())

    expect(closeButton()).toHaveAttribute('aria-expanded', 'true')
    expect(sidebar()).not.toHaveAttribute('inert')
    // The page is inert while the menu is open: focus cannot wander behind the
    // backdrop, and the header and content are not reachable from the keyboard.
    expect(screen.getByRole('heading', { name: 'Home page' }).closest('[inert]')).not.toBeNull()
    expect(document.body.style.overflow).toBe('hidden')
  })

  it('closes with the close button, and gives the scroll back', async () => {
    const user = renderShell('administrator', ADMIN_PERMISSIONS)
    await user.click(openButton())

    await user.click(closeButton())

    expect(openButton()).toHaveAttribute('aria-expanded', 'false')
    expect(sidebar()).toHaveAttribute('inert')
    expect(document.body.style.overflow).not.toBe('hidden')
  })

  it('closes on Escape and returns focus to the button', async () => {
    const user = renderShell('administrator', ADMIN_PERMISSIONS)
    await user.click(openButton())
    // Focus is somewhere inside the panel, as it is for a keyboard user.
    await user.tab()

    await user.keyboard('{Escape}')

    expect(openButton()).toHaveAttribute('aria-expanded', 'false')
    expect(openButton()).toHaveFocus()
  })

  it('closes when the backdrop is clicked', async () => {
    const user = renderShell('administrator', ADMIN_PERMISSIONS)
    await user.click(openButton())

    await user.click(screen.getByTestId('nav-backdrop'))

    expect(openButton()).toHaveAttribute('aria-expanded', 'false')
  })

  it('closes when a link is chosen, and the page it names is shown', async () => {
    const user = renderShell('teacher', ['tickets.view', 'tickets.create'])
    await user.click(openButton())

    await user.click(within(sidebar()).getByRole('link', { name: 'Announcements' }))

    expect(screen.getByRole('heading', { name: 'Announcements page' })).toBeInTheDocument()
    expect(openButton()).toHaveAttribute('aria-expanded', 'false')
  })

  it('closes when choosing the page you are already on', async () => {
    const user = renderShell('teacher', ['tickets.view'])
    await user.click(openButton())

    await user.click(within(sidebar()).getByRole('link', { name: 'Dashboard' }))

    expect(openButton()).toHaveAttribute('aria-expanded', 'false')
  })
})

describe('the navigation inside it', () => {
  it('marks the current page, and only that page', async () => {
    const user = renderShell('teacher', ['tickets.view', 'tickets.create'], '/app/announcements')
    await user.click(openButton())

    const current = within(sidebar()).getAllByRole('link', { current: 'page' })

    expect(current).toHaveLength(1)
    expect(current[0]).toHaveTextContent('Announcements')
  })

  it('does not light up "Tickets" on the Ticket management page', async () => {
    const user = renderShell('administrator', ADMIN_PERMISSIONS, '/app/tickets/manage')
    await user.click(openButton())

    const current = within(sidebar()).getAllByRole('link', { current: 'page' })

    expect(current.map((link) => link.textContent)).toEqual(['Ticket management'])
  })

  it('groups an administrator’s links under headings, in order', async () => {
    const user = renderShell('administrator', [
      ...ADMIN_PERMISSIONS,
      'locations.view',
      'floorplan.view',
      'predictions.view',
      'users.update',
      'ai.configure',
      'system.announcements.manage',
    ])
    await user.click(openButton())

    const headings = [
      'Service desk',
      'Maintenance',
      'Assets & places',
      'Communication',
      'Administration',
    ]
    for (const heading of headings) {
      // "Maintenance" is both a heading and a link, so match the heading element.
      expect(within(sidebar()).getByText(heading, { selector: 'p' })).toBeInTheDocument()
    }
    // Each group is a list named by its heading.
    const groupNames = within(sidebar())
      .getAllByRole('list')
      .map((list) => list.getAttribute('aria-labelledby'))
      .filter(Boolean)
      .map((id) => document.getElementById(id as string)?.textContent)
    expect(groupNames).toEqual(headings)
  })

  it('prints no heading for a group whose links were all withheld', async () => {
    const user = renderShell('administrator', ['users.view'])
    await user.click(openButton())

    expect(within(sidebar()).queryByText('Maintenance')).not.toBeInTheDocument()
    expect(within(sidebar()).getByText('Service desk')).toBeInTheDocument()
  })

  it.each([
    [
      'teacher',
      ['tickets.view', 'tickets.create'],
      ['Dashboard', 'Report a problem', 'My tickets', 'Announcements'],
    ],
    [
      'technician',
      ['tickets.update', 'maintenance.view'],
      [
        'Dashboard',
        'My work',
        'Completed work',
        'Maintenance',
        'My submissions',
        'My assets',
        'Announcements',
      ],
    ],
  ])('offers a %s exactly their own links', async (role, permissions, expected) => {
    const user = renderShell(role, permissions)
    await user.click(openButton())

    const labels = within(sidebar())
      .getAllByRole('link')
      .map((link) => link.textContent)

    expect(labels).toEqual(expected)
  })

  it('never offers a teacher an administrator surface, even holding every permission', async () => {
    const user = renderShell('teacher', [...ADMIN_PERMISSIONS, 'ai.configure', 'users.update'])
    await user.click(openButton())

    for (const name of [/Users/, /AI settings/, /Technicians/, /Registrations/, /Assets/]) {
      expect(within(sidebar()).queryByRole('link', { name })).not.toBeInTheDocument()
    }
  })
})
