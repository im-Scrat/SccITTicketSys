import { Table, TBody, Td, Th, THead, Tr } from '@/components/ui/Table'
import type { PlacedPc } from '../types'
import { PcStatusBadge } from './PcStatusBadge'

/**
 * The map as a table: every placed unit, its status in words, and where it sits.
 *
 * This is the map's text alternative, not an extra. A drawing is a visual-only
 * surface for anyone who cannot use a pointer or see it, so everything the SVG
 * says is also here, in reading order, with real column headers.
 */
export function PcUnitList({ roomName, pcs }: { roomName: string; pcs: PlacedPc[] }) {
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
            </Tr>
          ))}
        </TBody>
      </Table>
    </div>
  )
}
