import { forwardRef } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { groupNavItems, type NavItem } from './navConfig'

/** Ties the toggle button to the panel it discloses. */
export const SIDEBAR_ID = 'app-sidebar'

const EASE = '[transition-timing-function:var(--ease-out-quint)]'

interface NavMenuButtonProps {
  open: boolean
  onToggle: () => void
}

/**
 * The floating menu button, fixed to the top-left on every authenticated page.
 *
 * It is rendered **outside** the sticky header on purpose: the header is its own
 * stacking context (`z-20`), so a button inside it could never rise above the
 * panel and backdrop (`z-40`) — and it has to, because it is also the close
 * control. When open it sits over the panel's own header strip, which reserves
 * room for it, so the same spot that opened the menu closes it.
 *
 * 60px square, the same target size as every other control in this shell. The
 * three bars are one set of elements that rotate into an ✕, rather than two
 * icons swapped, so the transition reads as one object changing state.
 */
export const NavMenuButton = forwardRef<HTMLButtonElement, NavMenuButtonProps>(
  function NavMenuButton({ open, onToggle }, ref) {
    const bar = cn(
      'absolute left-0 top-[11px] h-[3px] w-6 rounded-full bg-current',
      'transition-[translate,rotate,scale,opacity] duration-200 motion-reduce:transition-none',
      EASE,
    )

    return (
      <button
        ref={ref}
        type="button"
        onClick={onToggle}
        aria-label={open ? 'Close navigation menu' : 'Open navigation menu'}
        aria-expanded={open}
        aria-controls={SIDEBAR_ID}
        data-print-hide
        className={cn(
          // z-50 puts it above the sidebar's z-40; Modal and Drawer are also
          // z-50 but portal later in the document, so they still cover it.
          'fixed left-4 top-4 z-50 flex size-15 items-center justify-center rounded-md border shadow-[var(--shadow-overlay-sm)] sm:left-6 lg:left-10',
          'transition-colors duration-150 motion-reduce:transition-none',
          open
            ? 'border-primary bg-primary-subtle text-primary-strong'
            : 'border-control-border bg-surface text-ink hover:bg-surface-sunken active:bg-primary-subtle',
        )}
      >
        <span className="relative block size-6" aria-hidden="true">
          <span className={cn(bar, open ? 'rotate-45' : '-translate-y-[7px]')} />
          <span className={cn(bar, open && 'scale-x-0 opacity-0')} />
          <span className={cn(bar, open ? '-rotate-45' : 'translate-y-[7px]')} />
        </span>
      </button>
    )
  },
)

interface AppSidebarProps {
  open: boolean
  onClose: () => void
  items: NavItem[]
  /** Shown under the title so the person knows whose menu this is. */
  roleName?: string
}

/**
 * The slide-out navigation: a left-anchored panel over a dimming backdrop.
 *
 * The panel stays mounted while closed so it can slide *out* as well as in
 * (an unmounted element cannot transition). Closed, it is `inert` and
 * `invisible` — `inert` takes it out of the tab order and the accessibility
 * tree, and `invisible` is transitioned so it only flips once the slide has
 * finished. Nothing here changes which items a role gets: `items` arrives
 * already filtered by `navItemsFor`, and every route is still guarded.
 */
export function AppSidebar({ open, onClose, items, roleName }: AppSidebarProps) {
  const { pathname } = useLocation()
  const sections = groupNavItems(items)

  return (
    <>
      <div
        aria-hidden="true"
        data-testid="nav-backdrop"
        data-print-hide
        onClick={onClose}
        className={cn(
          'fixed inset-0 z-40 bg-ink-strong/40 transition-[opacity,visibility] duration-300 motion-reduce:transition-none',
          EASE,
          open ? 'visible opacity-100' : 'pointer-events-none invisible opacity-0',
        )}
      />

      <aside
        id={SIDEBAR_ID}
        inert={!open}
        data-print-hide
        className={cn(
          // Capped below the viewport width so a strip of backdrop is always
          // left to tap on a phone.
          'fixed inset-y-0 left-0 z-40 flex w-[min(23rem,calc(100vw-3rem))] flex-col border-r-2 border-border bg-surface shadow-[var(--shadow-overlay-md)]',
          'transition-[translate,visibility] duration-300 motion-reduce:transition-none',
          EASE,
          open ? 'visible translate-x-0' : 'invisible -translate-x-full',
        )}
      >
        {/* Same height as the page header (py-4 + 60px row + 2px rule), and its
            left padding clears the floating button that sits over it. */}
        <div className="flex h-[5.875rem] shrink-0 flex-col justify-center border-b-2 border-border pl-23 pr-4 sm:pl-25 lg:pl-29">
          <p className="text-lg font-semibold leading-tight text-ink-strong">Menu</p>
          {roleName && (
            <p className="truncate text-xs font-medium capitalize leading-tight text-muted">
              {roleName}
            </p>
          )}
        </div>

        <nav
          aria-label="Primary"
          className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3"
        >
          {sections.map((section, index) => {
            const headingId = section.label ? `${SIDEBAR_ID}-group-${index}` : undefined

            return (
              <div key={section.label ?? 'top'} className={cn(index > 0 && 'mt-3')}>
                {section.label && (
                  <p id={headingId} className="px-3 pb-1 pt-2 text-xs font-semibold text-muted">
                    {section.label}
                  </p>
                )}

                <ul aria-labelledby={headingId} className="flex flex-col gap-1">
                  {section.items.map((item) => {
                    const Icon = item.icon
                    // "Ticket management" lives under /app/tickets/manage, which
                    // would otherwise also light up "Tickets" — in the styling
                    // *and* in aria-current, which a screen reader announces.
                    const excluded = item.excludes?.some((path) => pathname.startsWith(path))

                    return (
                      <li key={item.to}>
                        <NavLink
                          to={item.to}
                          end={item.end}
                          onClick={onClose}
                          aria-current={excluded ? false : undefined}
                          className={({ isActive }) => {
                            const active = isActive && !excluded

                            return cn(
                              'flex min-h-13 items-center gap-3 rounded-md border px-3 py-2 text-sm font-semibold leading-snug',
                              'transition-colors duration-150 motion-reduce:transition-none',
                              active
                                ? 'border-primary bg-primary-subtle text-primary-strong'
                                : 'border-transparent text-ink hover:bg-surface-sunken active:bg-primary-subtle',
                            )
                          }}
                        >
                          <Icon size={28} className="shrink-0" aria-hidden="true" />
                          {item.label}
                        </NavLink>
                      </li>
                    )
                  })}
                </ul>
              </div>
            )
          })}
        </nav>
      </aside>
    </>
  )
}
