import { Info } from 'lucide-react'
import { Button } from '@/components/ui'
import { Table, TBody, Td, Th, THead, Tr } from '@/components/ui/Table'
import type { PlacedPc } from '../types'
import { PcStatusBadge } from './PcStatusBadge'

/**
 * The map as a table: every placed unit, its status in words, and where it sits.
 *
 * This is the map's text alternative, not an extra. A drawing is a visual-only
 * surface for anyone who cannot use a pointer or see it, so everything the SVG
 * says is also here, in reading order, with real column headers.
 *
 * **The inspector's only entry point** (WP-G): a real, always-focusable
 * `<button>` per row, so opening a unit's maintenance history never depends on
 * the canvas's own pointer/keyboard model — which, in read-only mode, offers
 * no node interaction at all (WP-C: nodes are `role="img"`, not
 * `role="button"`, when the caller cannot edit), and in editable mode is
 * already busy meaning "select this unit to move it". `FloorPlanCanvas` is
 * untouched by WP-G, and deliberately stays that way: map selection there
 * already arms keyboard-move mode on Enter/Space, and also opening a
 * network-backed history panel on that same keystroke would surprise anyone
 * mid-move with two extra requests and a large panel they did not ask for.
 */
export function PcUnitList({
  roomName,
  pcs,
  onInspect,
}: {
  roomName: string
  pcs: PlacedPc[]
  onInspect?: (pcId: string) => void
}) {
  if (pcs.length === 0) return null

  return (
    <div className="overflow-hidden rounded-md border border-border bg-surface">
      <Table>
        <caption className="border-b border-border px-4 py-3 text-left text-sm font-semibold text-ink-strong">
          Units placed in {roomName}
        </caption>
        <THead>
          <tr>
            <Th>Unit</Th>
            <Th>Status</Th>
            <Th className="text-right">Position (x, y)</Th>
            {onInspect && <Th className="text-right">Details</Th>}
          </tr>
        </THead>
        <TBody>
          {pcs.map((pc) => (
            <Tr key={pc.id}>
              <Td>
                <span className="font-medium text-ink-strong">{pc.name}</span>{' '}
                <span className="slashed-zero font-mono text-xs text-muted">{pc.unit_code}</span>
              </Td>
              <Td>
                <PcStatusBadge status={pc.status} />
              </Td>
              <Td className="tnum text-right text-muted">
                {pc.x}, {pc.y}
              </Td>
              {onInspect && (
                <Td className="text-right">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => onInspect(pc.id)}
                    aria-label={`View details for ${pc.name}`}
                  >
                    <Info size={16} aria-hidden="true" />
                    Details
                  </Button>
                </Td>
              )}
            </Tr>
          ))}
        </TBody>
      </Table>
    </div>
  )
}
