/**
 * Chart colours as CSS custom properties (index.css, light and dark), passed to
 * SVG as `var(--token)` strings. They flip with the theme like every other role
 * token, and nothing injects a stylesheet — the production CSP
 * (`style-src 'self'`) would refuse one (SDD DD-84).
 */

const STATUS_SLUGS = [
  'open',
  'assigned',
  'in-progress',
  'on-hold',
  'resolved',
  'closed',
  'cancelled',
] as const

/** A status's colour, by slug — never by position, so filtering never repaints. */
export function statusColor(slug: string): string {
  return (STATUS_SLUGS as readonly string[]).includes(slug)
    ? `var(--chart-status-${slug})`
    : 'var(--chart-status-other)'
}

export const CHART = {
  current: 'var(--chart-current)',
  compare: 'var(--chart-compare)',
  grid: 'var(--chart-grid)',
  axis: 'var(--chart-axis)',
  bar: 'var(--chart-bar)',
  track: 'var(--chart-track)',
  surface: 'var(--surface)',
  ink: 'var(--ink)',
  muted: 'var(--muted)',
  /** The comparison series is dashed, so it differs by shape, not only colour. */
  compareDash: '6 4',
  /** The 16px floor applies inside charts too: axis ticks, legends, labels. */
  fontSize: 16,
} as const
