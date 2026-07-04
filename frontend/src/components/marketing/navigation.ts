/**
 * Shared navigation model for the public site — consumed by the navbar, the
 * mobile drawer, and the footer so link labels and targets never drift apart.
 *
 * In-page section targets are hash links (`href`); app routes use `to`.
 */

export interface NavLink {
  label: string
  href?: string
  to?: string
  /** Opens in a new tab (external / mail). */
  external?: boolean
}

export interface FooterColumn {
  title: string
  links: NavLink[]
}

/** Contact address surfaced in the footer + CTA (no backend form in Phase 2.1). */
export const CONTACT_EMAIL = 'hello@sccit.app'

/** Primary top-nav destinations (the platform's headline surfaces). */
export const primaryNav: NavLink[] = [
  { label: 'Platform', href: '#platform' },
  { label: 'Solutions', href: '#solutions' },
  { label: 'Intelligence', href: '#intelligence' },
  { label: 'Analytics', href: '#analytics' },
  { label: 'Security', href: '#security' },
]

export const footerColumns: FooterColumn[] = [
  {
    title: 'Platform',
    links: [
      { label: 'Ticketing & SLAs', href: '#platform' },
      { label: 'Asset lifecycle', href: '#platform' },
      { label: 'Preventive maintenance', href: '#platform' },
      { label: 'QR asset tracking', href: '#tracking' },
      { label: 'Interactive floor plans', href: '#floor-plan' },
    ],
  },
  {
    title: 'Intelligence',
    links: [
      { label: 'AI triage', href: '#intelligence' },
      { label: 'Grounded assistant', href: '#intelligence' },
      { label: 'Analytics & reports', href: '#analytics' },
    ],
  },
  {
    title: 'Solutions',
    links: [
      { label: 'Education', href: '#solutions' },
      { label: 'Business', href: '#solutions' },
      { label: 'Healthcare', href: '#solutions' },
      { label: 'Government', href: '#solutions' },
    ],
  },
  {
    title: 'Company',
    links: [
      { label: 'Enterprise security', href: '#security' },
      { label: 'System status', to: '/system-status' },
      { label: 'Contact', href: `mailto:${CONTACT_EMAIL}`, external: true },
      { label: 'Sign in', to: '/sign-in' },
    ],
  },
]
