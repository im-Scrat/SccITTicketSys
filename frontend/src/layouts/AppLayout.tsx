import {
  Building2,
  ChevronDown,
  ClipboardList,
  Gauge,
  HandHelping,
  HardDrive,
  Inbox,
  LayoutDashboard,
  LineChart,
  LogOut,
  Map as MapIcon,
  Megaphone,
  type LucideIcon,
  Ticket,
  UserCheck,
  UserCog,
  Users,
  Wrench,
} from 'lucide-react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { Logo } from '@/components/ui/Logo'
import { ThemeToggle } from '@/components/ui/ThemeToggle'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useLogout } from '@/features/auth/hooks/useAuthMutations'
import { NotificationMenu } from '@/features/notifications/components/NotificationMenu'

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
  {
    to: '/app/maintenance',
    label: 'Maintenance',
    icon: Wrench,
    permission: 'maintenance.view',
    end: true,
  },
  {
    to: '/app/maintenance/manage',
    label: 'Maintenance management',
    icon: Gauge,
    // Same shape as Ticket management: the permission is a floor every
    // technician clears, so the role is what keeps the estate view
    // administrator-only (SDD DD-55).
    permission: 'maintenance.view',
    roles: ['administrator'],
  },
  {
    // FR-WSR-009 asks for **one** dedicated navigation item covering both
    // proof-of-work records and support requests, so this is relabelled
    // rather than joined by a second item — two would have contradicted the
    // sentence they were meant to satisfy.
    //
    // **Technician-only** (Client decision, 2026-08-30). An earlier reading
    // let any holder of the floor open this, reasoning that the feed is
    // scoped to the caller either way so an administrator would simply see
    // their own. The Client has since ruled that the two roles own different
    // surfaces outright: "My submissions" is the technician's record of what
    // *they* submitted, and the administrator's equivalent is the request
    // inbox below — a surface for reviewing what others sent, not a personal
    // history. An administrator therefore has no My submissions page at all,
    // rather than an empty one.
    //
    // `maintenance.view` is kept as the floor beneath the role, matching the
    // shape used by the three management items: the permission says whether
    // you take part in the workflow, the role says which surface is yours.
    to: '/app/work-support',
    label: 'My submissions',
    icon: HandHelping,
    permission: 'maintenance.view',
    roles: ['technician'],
    end: true,
  },
  {
    // FR-WSR-010's own navigation item. Same shape as Ticket and Maintenance
    // management: the permission is a floor every technician clears, so the
    // role is what keeps the inbox administrator-only.
    to: '/app/work-support/manage',
    label: 'Support requests',
    icon: Inbox,
    permission: 'maintenance.view',
    roles: ['administrator'],
  },
  {
    // The reader is open to every authenticated role: an announcement is
    // addressed to people because of the role they hold, and the audience
    // scope is the server's (FR-NOT-011). No permission gates it, exactly as
    // no permission gates the notification centre.
    to: '/app/announcements',
    label: 'Announcements',
    icon: Megaphone,
    end: true,
    excludes: ['/app/announcements/manage'],
  },
  {
    // Management is the administrator's surface, on the same
    // permission-is-the-floor shape the other management items use.
    to: '/app/announcements/manage',
    label: 'Announcement management',
    icon: Megaphone,
    permission: 'system.announcements.manage',
  },
  { to: '/app/assets', label: 'Assets', icon: HardDrive, permission: 'assets.view' },
  { to: '/app/locations', label: 'Locations', icon: Building2, permission: 'locations.view' },
  {
    // Administrator-only, so — like Ticket management — the role sits beside the
    // permission: a per-user `floorplan.view` grant must not surface the item
    // for a Technician or Teacher (the page and API refuse them regardless).
    to: '/app/floor-plan',
    label: 'Floor plan',
    icon: MapIcon,
    permission: 'floorplan.view',
    roles: ['administrator'],
  },
  {
    // Administrator-only, on the floor-plan shape: the role sits beside the
    // permission, so a per-user `predictions.view` grant cannot surface the item
    // for a Technician or Teacher (the page and API refuse them regardless).
    to: '/app/predictions',
    label: 'Predictive maintenance',
    icon: LineChart,
    permission: 'predictions.view',
    roles: ['administrator'],
  },
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

          {/*
            The header utility cluster, which DESIGN.md §5 already names as
            where notifications belong: "top bar … holding global search,
            environment/org switcher, **notifications**, and the user menu."

            Not the primary nav row below. That row is 8–13 items wide depending
            on role and scrolls horizontally on narrow screens — and a badge on
            a scrolling row can scroll out of sight, which is the one thing a
            notification indicator must never do. This cluster is sticky and
            always visible.
          */}
          <div className="ml-auto flex items-center gap-3">
            <NotificationMenu />
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
