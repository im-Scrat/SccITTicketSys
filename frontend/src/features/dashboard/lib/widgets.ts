import type { DashboardWidget } from '../types'

/** Pick widgets by key, in the order given; keys the server did not send are skipped. */
export function pickWidgets(widgets: DashboardWidget[], keys: string[]): DashboardWidget[] {
  return keys.flatMap((key) => widgets.filter((widget) => widget.key === key))
}

/** Every widget not named in `keys`, in server order. */
export function otherWidgets(widgets: DashboardWidget[], keys: string[]): DashboardWidget[] {
  return widgets.filter((widget) => !keys.includes(widget.key))
}
