import { ChevronDown, LogOut, UserCog } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Logo } from '@/components/ui/Logo'
import { ThemeToggle } from '@/components/ui/ThemeToggle'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useLogout } from '@/features/auth/hooks/useAuthMutations'
import { AssistantLauncher } from '@/features/assistant/components/AssistantLauncher'
import { NotificationMenu } from '@/features/notifications/components/NotificationMenu'
import { useUserChannel } from '@/features/notifications/hooks/useUserChannel'
import { AppSidebar, NavMenuButton } from './AppSidebar'
import { navItemsFor } from './navConfig'

/**
 * Authenticated application shell.
 *
 * History: Phase 2.5 replaced a permanent left sidebar with a horizontal row of
 * large labelled targets across the top — a sidebar competed with the content
 * for the horizontal space large type needs, and a menu hidden behind a
 * hamburger was "one more thing to discover". That row was 8–13 items wide and
 * scrolled sideways on narrow screens.
 *
 * The client has since asked for the opposite trade, for every role: navigation
 * lives in a **slide-out sidebar** opened from a floating ☰ button at the
 * top-left, so the page itself is the full width and the header is one slim
 * row. What Phase 2.5 was protecting is kept rather than dropped — the button
 * is the same 60px target as every other control, every item still pairs its
 * icon with a text label, and the type is the same 18px+ scale.
 *
 * The menu is an overlay, not a layout column: opening it never reflows or
 * shifts the page underneath. While it is open the page is `inert` (a focus
 * trap that needs no code, and it stops background scrolling being driven from
 * the keyboard) and the document does not scroll.
 */
export function AppLayout() {
  const { user, hasPermission } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const logout = useLogout()

  // Phase 2.9: each role has its own navigation (layouts/navConfig.ts).
  const items = navItemsFor(user?.role.slug, hasPermission)

  // Phase 2.9: the user's own realtime channel — notifications and "your AI
  // analysis is ready" arrive as pushes while it is live.
  useUserChannel()

  // The menu is open *for the page it was opened on*: any route change —
  // a link, the browser's back button, a redirect — closes it, without an
  // effect to keep two pieces of state in step. Resetting state during render
  // is React's sanctioned way to derive it from a changing input.
  const [openAt, setOpenAt] = useState<string | null>(null)
  if (openAt !== null && openAt !== pathname) setOpenAt(null)
  const navOpen = openAt !== null

  const toggleRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!navOpen) return

    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpenAt(null)
      // Focus may be on a link inside the panel, which is about to go inert.
      toggleRef.current?.focus()
    }
    document.addEventListener('keydown', onKey)

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [navOpen])

  const signOut = async () => {
    await logout.mutateAsync().catch(() => undefined)
    navigate('/sign-in', { replace: true })
  }

  return (
    <div className="bg-bg">
      <NavMenuButton
        ref={toggleRef}
        open={navOpen}
        onToggle={() => setOpenAt(navOpen ? null : pathname)}
      />
      <AppSidebar
        open={navOpen}
        onClose={() => setOpenAt(null)}
        items={items}
        roleName={user?.role.name}
      />

      <div inert={navOpen} className="flex min-h-dvh flex-col">
        <header className="sticky top-0 z-20 border-b-2 border-border bg-surface" data-print-hide>
          <div className="flex w-full items-center gap-3 px-4 py-4 sm:gap-6 sm:px-6 lg:px-10">
            {/* Reserves the space the fixed menu button floats over, so the
                logo never sits underneath it. */}
            <div className="size-15 shrink-0" aria-hidden="true" />

            <NavLink
              to="/app"
              className="flex items-center rounded-md"
              aria-label="SccIT — workspace home"
            >
              {/* Wrapped, not classed: cn() has no tailwind-merge, so `hidden` on
                  Logo itself would lose to its own `inline-flex`. */}
              <span className="sm:hidden">
                <Logo withWordmark={false} />
              </span>
              <span className="hidden sm:inline-flex">
                <Logo />
              </span>
            </NavLink>

            {/*
            The header utility cluster, which DESIGN.md §5 already names as
            where notifications belong: "top bar … holding global search,
            environment/org switcher, **notifications**, and the user menu."

            Always visible: the header is sticky, so a notification badge can
            never scroll out of sight — the one thing it must never do.
          */}
            <div className="ml-auto flex items-center gap-1 sm:gap-3">
              <NotificationMenu />
              <ThemeToggle />

              <details className="group relative">
                <summary className="flex h-15 cursor-pointer list-none items-center gap-2 rounded-md border border-transparent px-2 text-sm font-semibold hover:border-control-border hover:bg-surface-sunken sm:gap-3 sm:px-4 [&::-webkit-details-marker]:hidden">
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
        </header>

        {/* Full-bleed, single column. Individual pages cap their *prose* at
            `.measure`; tables and toolbars use the full width. */}
        <main className="w-full flex-1 px-6 py-10 lg:px-10">
          <Outlet />
        </main>

        {/* Phase 2.9: the AI assistant, available on every authenticated page. */}
        <AssistantLauncher />
      </div>
    </div>
  )
}
