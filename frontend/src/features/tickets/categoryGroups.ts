import type { OptionItem } from './types'

/**
 * How the ticket categories are grouped for display.
 *
 * **Only the grouping and the order live here — never the label.** A category's
 * label and its filter value come from the API (`/tickets/options`), which reads
 * the `ticket_categories` table. Keying on the slug keeps one source of truth for
 * the words the user sees, and means a rename in the database cannot leave this
 * file showing a stale name.
 *
 * The slugs are the seeded ones (`TicketLookupSeeder`, and the migration that adds
 * them to an existing database). A slug listed here that the API does not return
 * is simply skipped, so an out-of-date database degrades to a shorter group, never
 * to a broken filter.
 *
 * Neither group has a catch-all of its own: there is one "Other", offered under
 * "Other Categories" (Client decision 2026-10-16). The group-level "Other Common
 * Issues" / "Other Advanced Issues" were retired on the server, which stops
 * returning them; they are deliberately absent here too, so that if one ever came
 * back it would show under "Other Categories" rather than inside a group.
 */
const COMMON_SLUGS = [
  'hardware',
  'software',
  'network',
  'performance-slowness',
  'os-updates',
  'power-issue',
  'audio-video-output',
  'browser-web',
] as const

const ADVANCED_SLUGS = [
  'peripheral',
  'account-access',
  'bios-firmware',
  'blue-screen',
  'missing-software',
] as const

export interface CategoryGroup {
  key: 'common' | 'advanced' | 'other'
  heading: string
  options: OptionItem[]
}

const DEFINED_GROUPS: Array<{
  key: 'common' | 'advanced'
  heading: string
  slugs: readonly string[]
}> = [
  { key: 'common', heading: 'Common Problems', slugs: COMMON_SLUGS },
  { key: 'advanced', heading: 'Advanced / Uncommon Problems', slugs: ADVANCED_SLUGS },
]

/**
 * Split the API's category list into the two defined groups, in the defined order.
 *
 * **Nothing is dropped.** A category the API returns that neither group names — the
 * legacy `other` row, or one an administrator adds later — lands in a trailing
 * "Other Categories" group instead of vanishing, because a ticket carrying it must
 * stay reachable through the filter. Empty groups are omitted.
 */
export function groupCategories(categories: OptionItem[]): CategoryGroup[] {
  const bySlug = new Map(categories.map((category) => [category.value, category]))
  const placed = new Set<string>()

  const groups: CategoryGroup[] = DEFINED_GROUPS.map(({ key, heading, slugs }) => {
    const options: OptionItem[] = []
    for (const slug of slugs) {
      const category = bySlug.get(slug)
      if (category) {
        options.push(category)
        placed.add(slug)
      }
    }
    return { key, heading, options }
  })

  const rest = categories.filter((category) => !placed.has(category.value))
  if (rest.length > 0) {
    groups.push({ key: 'other', heading: 'Other Categories', options: rest })
  }

  return groups.filter((group) => group.options.length > 0)
}
