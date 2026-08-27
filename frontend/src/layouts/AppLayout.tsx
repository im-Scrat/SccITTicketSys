import {
  Building2,
  ChevronDown,
  ClipboardList,
  HardDrive,
  LayoutDashboard,
  LogOut,
  type LucideIcon,
  Ticket,
  UserCheck,
  UserCog,
  Users,
} from 'lucide-react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { Logo } from '@/components/ui/Logo'
import { ThemeToggle } from '@/components/ui/ThemeToggle'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useLogout } from '@/features/auth/hooks/useAuthMutations'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  /** When set, the item only renders if the user holds this permission. */
  permission?: string
  /**
   * The Tickets exception (SDD DD-40) — see the note below. Prefer `permission`
   * for everything else.
   */
  roles?: string[]
  /**
   * Sub-paths that belong to a *different* nav item. "Ticket management" lives
   * under `/app/tickets/manage`, which would otherwise light up "Tickets" as
   * well — two highlighted items telling the reader they are in two places.
   */
  excludes?: string[]
}

/**
 * Nav items are gated by *permission*, never by role name, so the navigation
 * always shows exactly what the API will allow. `locations.view`, `assets.view`
 * and `users.*` are seeded to Administrators only — so Assets, Locations, Users
 * and Registrations are absent for Technicians and Teachers — while an
 * individual grant (FR-USER-004) opens both the nav item and the endpoints
 * together, with no second rule to keep in sync.
 *
 * **Tickets is the one module a permission cannot separate.** All three roles
 * hold `tickets.view` by design, and which *rows* each reaches is decided by
 * row-level visibility rather than by the grant (SDD DD-40). So "Tickets" is
 * offered to everyone who holds it and each role lands on its own surface:
 * `/app/tickets` is the community feed, and a Technician — for whom the feed is
 * refused outright — is forwarded from there to their assigned queue. Only
 * "Ticket management", the Administrator's oversight surface, is gated by role,
 * because no permission distinguishes it.
 *
 * Hiding is a convenience, never the control: typing one of these URLs lands on
 * the Forbidden page and the API refuses the calls behind it regardless.
 */
const NAV_ITEMS: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: LayoutDashboard, end: true },
  {
    to: '/app/tickets',
    label: 'Tickets',
    icon: Ticket,
    permission: 'tickets.view',
    excludes: ['/app/tickets/manage'],
  },
  {
    to: '/app/tickets/manage',
    label: 'Ticket management',
    icon: ClipboardList,
    permission: 'tickets.view',
    roles: ['administrator'],
  },
  { to: '/app/assets', label: 'Assets', icon: HardDrive, permission: 'assets.view' },
  { to: '/app/locations', label: 'Locations', icon: Building2, permission: 'locations.view' },
  { to: '/app/users', label: 'Users', icon: Users, permission: 'users.view' },
  { to: '/app/registrations', label: 'Registrations', icon: UserCheck, permission: 'users.update' },
]

function navLinkClass({ isActive }: { isActive: boolean }): string {
  return cn(
    // 60px tall, generous horizontal padding: a target that needs no aiming at.
    'flex h-15 shrink-0 items-center gap-3 rounded-md border px-5 text-sm font-semibold',
    isActive
      ? 'border-primary bg-primary-subtle text-primary-strong'
      : 'border-transparent text-ink hover:border-control-border hover:bg-surface-sunken',
  )
}

/**
 * Authenticated application shell.
 *
 * Phase 2.5 replaced the left sidebar with a **single-column, full-bleed**
 * layout: navigation runs across the top as large labelled targets, and the
 * content below spans the full width of the screen rather than sitting in a
 * centred 76rem column. Two reasons, both from the accessibility directive —
 * a sidebar competes with the content for horizontal space that large type
 * needs, and a vertical stack is the one layout that survives being read at any
 * window size without a second mental model.
 *
 * Icons are never alone: every one is paired with its text label, at 32px, so
 * the meaning does not depend on recognising a glyph.
 */
export function AppLayout() {
  const { user, hasPermission } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const logout = useLogout()

  const items = NAV_ITEMS.filter(
    (item) =>
      (!item.permission || hasPermission(item.permission)) &&
      (!item.roles || item.roles.includes(user?.role.slug ?? '')),
  )

  const signOut = async () => {
    await logout.mutateAsync().catch(() => undefined)
    navigate('/sign-in', { replace: true })
  }

  return (
    <div className="flex min-h-dvh flex-col bg-bg">
      <header className="sticky top-0 z-20 border-b-2 border-border bg-surface" data-print-hide>
        <div className="flex w-full items-center gap-6 px-6 py-4 lg:px-10">
          <NavLink to="/app" className="rounded-md" aria-label="SccIT — workspace home">
            <Logo />
          </NavLink>

          <div className="ml-auto flex items-center gap-3">
            <ThemeToggle />

            <details className="group relative">
              <summary className="flex h-15 cursor-pointer list-none items-center gap-3 rounded-md border border-transparent px-4 text-sm font-semibold hover:border-control-border hover:bg-surface-sunken [&::-webkit-details-marker]:hidden">
                <span className="flex size-11 items-center justify-center rounded-full bg-primary-subtle text-base font-bold text-primary-strong">
                  {(user?.first_name?.[0] ?? '?').toUpperCase()}
                </span>
                <span className="hidden text-left sm:block">
                  <span className="block leading-tight text-ink-strong">{user?.name}</span>
                  <span className="block text-xs font-medium capitalize leading-tight text-muted">
                    {user?.role.name}
                  </span>
                </span>
                <ChevronDown size={24} className="text-muted" aria-hidden="true" />
              </summary>

              <div className="absolute right-0 z-30 mt-2 w-72 rounded-lg border-2 border-border bg-surface p-2 shadow-[var(--shadow-overlay-md)]">
                <div className="border-b border-border px-3 py-3 sm:hidden">
                  <p className="text-sm font-semibold text-ink-strong">{user?.name}</p>
                  <p className="text-xs capitalize text-muted">{user?.role.name}</p>
                </div>
                <NavLink
                  to="/app/account"
                  className="flex h-15 w-full items-center gap-3 rounded-md px-3 text-left text-sm font-semibold text-ink hover:bg-surface-sunken"
                >
                  <UserCog size={32} aria-hidden="true" />
                  Account
                </NavLink>
                <button
                  type="button"
                  onClick={signOut}
                  className="flex h-15 w-full items-center gap-3 rounded-md px-3 text-left text-sm font-semibold text-ink hover:bg-surface-sunken"
                >
                  <LogOut size={32} aria-hidden="true" />
                  Sign out
                </button>
              </div>
            </details>
          </div>
        </div>

        {/* Primary navigation: horizontal, scrollable on narrow screens, never
            collapsed behind a hamburger — a hidden menu is one more thing to
            discover. */}
        <nav aria-label="Primary" className="flex gap-2 overflow-x-auto px-6 pb-3 lg:px-10">
          {items.map((item) => {
            const Icon = item.icon
            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  navLinkClass({
                    isActive: isActive && !item.excludes?.some((path) => pathname.startsWith(path)),
                  })
                }
              >
                <Icon size={32} aria-hidden="true" />
                {item.label}
              </NavLink>
            )
          })}
        </nav>
      </header>

      {/* Full-bleed, single column. Individual pages cap their *prose* at
          `.measure`; tables and toolbars use the full width. */}
      <main className="w-full flex-1 px-6 py-10 lg:px-10">
        <Outlet />
      </main>
    </div>
  )
}
