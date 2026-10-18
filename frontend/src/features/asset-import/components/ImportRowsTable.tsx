import { Badge, Checkbox, Table, TBody, Td, Th, THead, Tr, TruncatedText } from '@/components/ui'
import { ROW_STATUS_TONE } from '../lib/steps'
import type { ImportRow } from '../types'

function identity(row: ImportRow): { code: string; name: string } {
  const v = row.values
  const o = row.original
  return {
    code: String(v.unit_code ?? v.asset_tag ?? o.unit_code ?? o.asset_tag ?? '—'),
    name: String(v.pc_name ?? v.name ?? o.name ?? ''),
  }
}

function firstProblem(row: ImportRow): string {
  return (
    row.issues.find((i) => i.severity !== 'info' && !row.acknowledged.includes(i.code))?.message ??
    ''
  )
}

/**
 * The review rows (Phase 2.10 R6): a table from `md`, cards below it. A row
 * opens its panel; selection drives the bulk bar. The first problem is shown
 * in words so the list can be read without opening every row.
 */
export function ImportRowsTable({
  rows,
  mixed,
  selected,
  onToggle,
  onToggleAll,
  onOpen,
}: {
  rows: ImportRow[]
  mixed: boolean
  selected: Set<number>
  onToggle: (rowNumber: number) => void
  onToggleAll: (checked: boolean) => void
  onOpen: (row: ImportRow) => void
}) {
  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.row_number))

  return (
    <>
      <div className="hidden md:block">
        <Table>
          <THead>
            <tr>
              <Th className="w-12">
                <Checkbox
                  label="Select every row on this page"
                  labelHidden
                  checked={allSelected}
                  onChange={(e) => onToggleAll(e.target.checked)}
                />
              </Th>
              <Th className="w-20">Row</Th>
              {mixed && <Th>Kind</Th>}
              <Th>Identifier</Th>
              <Th>Name</Th>
              <Th>Status</Th>
              <Th>What to check</Th>
            </tr>
          </THead>
          <TBody>
            {rows.map((row) => {
              const { code, name } = identity(row)
              return (
                <Tr key={row.row_number} onClick={() => onOpen(row)}>
                  <Td>
                    <Checkbox
                      label={`Select row ${row.row_number}`}
                      labelHidden
                      checked={selected.has(row.row_number)}
                      onChange={() => onToggle(row.row_number)}
                    />
                  </Td>
                  <Td className="tabular-nums">
                    <button
                      type="button"
                      className="font-semibold text-primary-strong hover:underline"
                      onClick={() => onOpen(row)}
                    >
                      {row.row_number}
                    </button>
                  </Td>
                  {mixed && <Td>{row.entity_label ?? '—'}</Td>}
                  <Td className="whitespace-nowrap font-mono text-sm">{code}</Td>
                  <Td className="max-w-[16rem]">
                    <TruncatedText text={name || '—'} />
                  </Td>
                  <Td>
                    <Badge tone={ROW_STATUS_TONE[row.status]}>{row.status_label}</Badge>
                  </Td>
                  <Td className="max-w-[28rem] text-sm text-muted">
                    <TruncatedText text={firstProblem(row) || '—'} lines={2} />
                  </Td>
                </Tr>
              )
            })}
          </TBody>
        </Table>
      </div>

      <ul className="flex flex-col gap-3 md:hidden">
        {rows.map((row) => {
          const { code, name } = identity(row)
          const problem = firstProblem(row)
          return (
            <li key={row.row_number} className="flex gap-3 rounded-md border border-border p-3">
              <Checkbox
                label={`Select row ${row.row_number}`}
                labelHidden
                checked={selected.has(row.row_number)}
                onChange={() => onToggle(row.row_number)}
              />
              <button
                type="button"
                onClick={() => onOpen(row)}
                className="flex min-w-0 flex-1 flex-col items-start gap-1 text-left"
              >
                <span className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold text-ink">Row {row.row_number}</span>
                  <Badge tone={ROW_STATUS_TONE[row.status]}>{row.status_label}</Badge>
                </span>
                <span className="break-words font-mono text-sm text-ink">{code}</span>
                {name && <span className="break-words text-sm text-ink">{name}</span>}
                {problem && <span className="text-sm text-muted">{problem}</span>}
              </button>
            </li>
          )
        })}
      </ul>
    </>
  )
}
