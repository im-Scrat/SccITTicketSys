import type { Drilldown } from '../types'

/** The href of a figure's drill-down: the list that totals exactly to it. */
export function drilldownHref(drilldown: Drilldown | null | undefined): string | undefined {
  if (!drilldown) return undefined
  return `${drilldown.path}?${new URLSearchParams(drilldown.query).toString()}`
}
