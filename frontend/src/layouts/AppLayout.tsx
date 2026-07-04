import { ChevronDown, LayoutDashboard, LogOut, type LucideIcon, UserCheck } from 'lucide-react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
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
}

const NAV_ITEMS: NavItem[] = [
  { to: '/app', label: 'Home', icon: LayoutDashboard, end: true },
  { to: '/app/registrations', label: 'Registrations', icon: UserCheck, permission: 'users.update' },
]

function navLinkClass({ isActive }: { isActive: boolean }): string {
  return cn(
    'flex items-center gap-2.5 rounded-sm px-2.5 py-1.5 text-sm font-medium',
    isActive ? 'bg-primary-subtle text-primary-strong' : 'text-ink hover:bg-surface-sunken',
  )
}

/**
 * Authenticated application shell: top bar + permission-aware left nav. This is
 * the minimal scaffold that hosts the Phase 2.2 authenticated surfaces; future
 * modules add their own nav items and routes without changing the shell.
 */
export function AppLayout() {
  const { user, hasPermission } = useAuth()
  const navigate = useNavigate()
  const logout = useLogout()

  const items = NAV_ITEMS.filter((item) => !item.permission || hasPermission(item.permission))

  const signOut = async () => {
    await logout.mutateAsync().catch(() => undefined)
    navigate('/sign-in', { replace: true })
  }

  return (
    <div className="flex min-h-dvh flex-col bg-bg">
      <header className="sticky top-0 z-10 flex items-center gap-4 border-b border-border bg-surface px-4 py-2.5 sm:px-6">
        <NavLink to="/app" className="rounded-sm" aria-label="SccIT — workspace home">
          <Logo />
        </NavLink>

        <div className="ml-auto flex items-center gap-2">
          <ThemeToggle />

          <details className="group relative">
            <summary className="flex cursor-pointer list-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-surface-sunken [&::-webkit-details-marker]:hidden">
              <span className="flex size-7 items-center justify-center rounded-full bg-primary-subtle text-xs font-semibold text-primary-strong">
                {(user?.first_name?.[0] ?? '?').toUpperCase()}
              </span>
              <span className="hidden text-left sm:block">
                <span className="block leading-tight text-ink-strong">{user?.name}</span>
                <span className="block text-xs capitalize leading-tight text-muted">
                  {user?.role.name}
                </span>
              </span>
              <ChevronDown size={16} className="text-muted" aria-hidden="true" />
            </summary>

            <div className="absolute right-0 mt-1 w-52 rounded-md border border-border bg-surface p-1 shadow-[var(--shadow-overlay-sm)]">
              <div className="border-b border-border px-2.5 py-2 sm:hidden">
                <p className="text-sm text-ink-strong">{user?.name}</p>
                <p className="text-xs capitalize text-muted">{user?.role.name}</p>
              </div>
              <button
                type="button"
                onClick={signOut}
                className="flex w-full items-center gap-2 rounded-sm px-2.5 py-1.5 text-left text-sm text-ink hover:bg-surface-sunken"
              >
                <LogOut size={16} aria-hidden="true" />
                Sign out
              </button>
            </div>
          </details>
        </div>
      </header>

      <div className="mx-auto flex w-full max-w-[76rem] flex-1 flex-col gap-4 px-4 py-5 sm:px-6 md:flex-row md:gap-6">
        <aside className="md:w-56 md:shrink-0">
          <nav aria-label="Primary" className="flex gap-1 overflow-x-auto md:flex-col">
            {items.map((item) => {
              const Icon = item.icon
              return (
                <NavLink key={item.to} to={item.to} end={item.end} className={navLinkClass}>
                  <Icon size={16} aria-hidden="true" />
                  {item.label}
                </NavLink>
              )
            })}
          </nav>
        </aside>

        <main className="min-w-0 flex-1">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
