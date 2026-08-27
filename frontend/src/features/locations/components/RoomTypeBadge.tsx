import { Badge } from '@/components/ui'
import type { RoomType } from '../types'

/**
 * A room's purpose, as a quiet neutral badge.
 *
 * Deliberately *not* a StatusPill: the status vocabulary (success/warning/danger)
 * carries operational meaning across the platform, and a room's type is a
 * category, not a state. Spending status hues on it would dilute the signal
 * (DESIGN.md — the Reserved-Red and One Voice rules). The label always carries
 * the meaning, so nothing depends on colour.
 */
export function RoomTypeBadge({ type, label }: { type: RoomType; label?: string }) {
  return <Badge tone="neutral">{label ?? fallbackLabel(type)}</Badge>
}

/** Sentence-case label, mirroring the backend `RoomType::label()`. */
function fallbackLabel(type: RoomType): string {
  const labels: Record<RoomType, string> = {
    laboratory: 'Laboratory',
    office: 'Office',
    storage: 'Storage',
    server_room: 'Server room',
    faculty_room: 'Faculty room',
    library: 'Library',
    other: 'Other',
  }
  return labels[type]
}
