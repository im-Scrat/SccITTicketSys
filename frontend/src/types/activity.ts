/**
 * A single audit-timeline entry, as served by any per-record `…/audit` endpoint
 * (`activity_logs`; SRS FR-AUD-003/004).
 *
 * Shared rather than per-slice: Users, Locations and every later module render
 * the same timeline component from the same shape.
 */
export interface ActivityEntry {
  id: number
  action: string
  label: string
  description: string | null
  module: string | null
  actor?: { id: string; name: string } | null
  properties: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}
