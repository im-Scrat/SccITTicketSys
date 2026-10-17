import {
  BrainCircuit,
  Building2,
  ClipboardList,
  FilePlus2,
  Gauge,
  HandHelping,
  HardDrive,
  History,
  Inbox,
  LayoutDashboard,
  LineChart,
  ListChecks,
  type LucideIcon,
  Map as MapIcon,
  Megaphone,
  PackageCheck,
  Ticket,
  UserCheck,
  Users,
  UsersRound,
  Wrench,
} from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  /** When set, the item only renders if the user holds this permission. */
  permission?: string
  /**
   * Sub-paths that belong to a *different* nav item ("Ticket management" lives
   * under `/app/tickets/manage`, which would otherwise light up "Tickets" too).
   */
  excludes?: string[]
  /**
   * The heading this item sits under in the slide-out menu. Presentation only:
   * it never affects who sees the item. Items without one (the dashboard) stand
   * above the first heading. Consecutive items with the same group are rendered
   * together — see {@link groupNavItems}.
   */
  group?: string
}

export type RoleSlug = 'administrator' | 'technician' | 'teacher'

/**
 * Phase 2.9 — **one navigation per role**, not one list filtered three ways.
 *
 * The three roles do different jobs (PRODUCT.md): a teacher reports and
 * follows faults, a technician works an assigned queue, an administrator
 * oversees everything. A shared list filtered by permission gave each of them
 * the same mental model with holes in it — a teacher saw "My assets", a
 * technician saw a "Tickets" feed that only redirected them away. Each role
 * now gets the set that matches its work, in the order of its work.
 *
 * **The permission on every item stays** as defence in depth, so a per-user
 * deny still hides an item. But the *role* decides which items exist at all:
 * a per-user grant cannot put an administrator surface into a teacher's nav.
 *
 * Hiding is a convenience, never the control: every route is still guarded
 * (RequireRole / RequirePermission) and the API refuses regardless.
 */
const TEACHER_NAV: NavItem[] = [
  // The teacher dashboard *is* the community feed (Phase 2.9).
  { to: '/app', label: 'Dashboard', icon: LayoutDashboard, end: true },
  {
    to: '/app/tickets/new',
    label: 'Report a problem',
    icon: FilePlus2,
    permission: 'tickets.create',
    group: 'Help desk',
  },
  {
    to: '/app/tickets/mine',
    label: 'My tickets',
    icon: Ticket,
    permission: 'tickets.view',
    group: 'Help desk',
  },
  {
    to: '/app/announcements',
    label: 'Announcements',
    icon: Megaphone,
    end: true,
    group: 'Communication',
  },
]

const TECHNICIAN_NAV: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: LayoutDashboard, end: true },
  // The community feed is refused to technicians (SDD DD-40); their tickets
  // are the ones assigned to them.
  {
    to: '/app/tickets/assigned',
    label: 'My work',
    icon: ListChecks,
    permission: 'tickets.update',
    group: 'Work',
  },
  {
    to: '/app/tickets/history',
    label: 'Completed work',
    icon: History,
    permission: 'tickets.update',
    group: 'Work',
  },
  {
    to: '/app/maintenance',
    label: 'Maintenance',
    icon: Wrench,
    permission: 'maintenance.view',
    end: true,
    group: 'Work',
  },
  // FR-WSR-009's one item for proof of work and support requests —
  // technician-only by Client decision (2026-08-30).
  {
    to: '/app/work-support',
    label: 'My submissions',
    icon: HandHelping,
    permission: 'maintenance.view',
    end: true,
    group: 'Work',
  },
  // Custody is a fact about an asset, not a grant: no permission (Phase 2.9).
  { to: '/app/my-assets', label: 'My assets', icon: PackageCheck, group: 'Work' },
  {
    to: '/app/announcements',
    label: 'Announcements',
    icon: Megaphone,
    end: true,
    group: 'Communication',
  },
]

// Ordered so that each group is one contiguous run: the menu renders a heading
// per run, so a group split in two would print its heading twice.
const ADMINISTRATOR_NAV: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: LayoutDashboard, end: true },
  {
    to: '/app/tickets',
    label: 'Tickets',
    icon: Ticket,
    permission: 'tickets.view',
    excludes: ['/app/tickets/manage'],
    group: 'Service desk',
  },
  {
    to: '/app/tickets/manage',
    label: 'Ticket management',
    icon: ClipboardList,
    permission: 'tickets.view',
    group: 'Service desk',
  },
  {
    to: '/app/technicians',
    label: 'Technicians',
    icon: UsersRound,
    permission: 'users.view',
    group: 'Service desk',
  },
  {
    to: '/app/work-support/manage',
    label: 'Support requests',
    icon: Inbox,
    permission: 'maintenance.view',
    group: 'Service desk',
  },
  {
    to: '/app/maintenance',
    label: 'Maintenance',
    icon: Wrench,
    permission: 'maintenance.view',
    end: true,
    group: 'Maintenance',
  },
  {
    to: '/app/maintenance/manage',
    label: 'Maintenance management',
    icon: Gauge,
    permission: 'maintenance.view',
    group: 'Maintenance',
  },
  {
    to: '/app/predictions',
    label: 'Predictive maintenance',
    icon: LineChart,
    permission: 'predictions.view',
    group: 'Maintenance',
  },
  { to: '/app/my-assets', label: 'My assets', icon: PackageCheck, group: 'Assets & places' },
  {
    to: '/app/assets',
    label: 'Assets',
    icon: HardDrive,
    permission: 'assets.view',
    group: 'Assets & places',
  },
  {
    to: '/app/locations',
    label: 'Locations',
    icon: Building2,
    permission: 'locations.view',
    group: 'Assets & places',
  },
  {
    to: '/app/floor-plan',
    label: 'Floor plan',
    icon: MapIcon,
    permission: 'floorplan.view',
    group: 'Assets & places',
  },
  {
    to: '/app/announcements',
    label: 'Announcements',
    icon: Megaphone,
    end: true,
    excludes: ['/app/announcements/manage'],
    group: 'Communication',
  },
  {
    to: '/app/announcements/manage',
    label: 'Announcement management',
    icon: Megaphone,
    permission: 'system.announcements.manage',
    group: 'Communication',
  },
  {
    to: '/app/users',
    label: 'Users',
    icon: Users,
    permission: 'users.view',
    group: 'Administration',
  },
  {
    to: '/app/registrations',
    label: 'Registrations',
    icon: UserCheck,
    permission: 'users.update',
    group: 'Administration',
  },
  {
    to: '/app/ai-settings',
    label: 'AI settings',
    icon: BrainCircuit,
    permission: 'ai.configure',
    group: 'Administration',
  },
]

const NAV_BY_ROLE: Record<RoleSlug, NavItem[]> = {
  teacher: TEACHER_NAV,
  technician: TECHNICIAN_NAV,
  administrator: ADMINISTRATOR_NAV,
}

/**
 * The navigation for this role, minus anything the user's effective
 * permissions withhold. An unknown role gets the dashboard only.
 */
export function navItemsFor(
  role: string | undefined,
  hasPermission: (permission: string) => boolean,
): NavItem[] {
  const items = NAV_BY_ROLE[role as RoleSlug] ?? [NAV_BY_ROLE.teacher[0]]

  return items.filter((item) => !item.permission || hasPermission(item.permission))
}

export interface NavSection {
  /** Heading text, or null for the leading items that stand above any heading. */
  label: string | null
  items: NavItem[]
}

/**
 * Fold an already-filtered item list into the sections the menu prints. A new
 * section starts whenever the group changes, so a heading never appears for a
 * group whose items were all filtered out by permission.
 */
export function groupNavItems(items: NavItem[]): NavSection[] {
  const sections: NavSection[] = []

  for (const item of items) {
    const label = item.group ?? null
    const last = sections[sections.length - 1]

    if (last && last.label === label) {
      last.items.push(item)
    } else {
      sections.push({ label, items: [item] })
    }
  }

  return sections
}
