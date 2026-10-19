import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { Badge, Select, Skeleton } from '@/components/ui'
import { fetchMeanings } from '../api/assetImportApi'
import type { MeaningTable } from '../types'

const FIELD_LABEL: Record<string, string> = {
  'status.pc_unit': 'PC unit status',
  'status.asset': 'Asset status',
  condition: 'Condition',
  category: 'Category',
}

/**
 * What each distinct value of one status, condition or category column means
 * (Phase 2.10 R6, Appendix B.3). Unknown values come first; a likely meaning
 * is pre-selected and marked "Check this"; a value that means different
 * things in different schools (CPU, AVR, system unit) is never pre-selected.
 * A value left without a meaning makes its rows invalid — the import never
 * guesses a status.
 */
export function ValueMeaningTable({
  importId,
  column,
  columnLabel,
  target,
  initial,
  meanings,
  onChange,
  onPrefill,
}: {
  importId: string
  column: number
  columnLabel: string
  target: string
  initial: MeaningTable[] | undefined
  meanings: Record<string, Record<string, string | null>>
  onChange: (field: string, normalized: string, meaning: string | null) => void
  onPrefill: (field: string, values: Record<string, string>) => void
}) {
  const query = useQuery({
    queryKey: ['asset-imports', 'detail', importId, 'meanings', column, target],
    queryFn: () => fetchMeanings(importId, column, target),
    initialData: initial,
    staleTime: 60_000,
  })

  // A column just pointed at a status: take the server's confident proposals.
  useEffect(() => {
    for (const table of query.data ?? []) {
      const proposals: Record<string, string> = {}
      for (const value of table.values) {
        if (value.suggested && ['exact', 'synonym', 'likely'].includes(value.confidence)) {
          proposals[value.normalized] = value.suggested
        }
      }
      onPrefill(table.field, proposals)
    }
    // onPrefill is stable for the parent's lifetime; only new data matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query.data])

  if (query.isLoading) return <Skeleton className="h-32 w-full" />
  if (!query.data || query.data.length === 0) return null

  return (
    <div className="flex flex-col gap-6">
      {query.data.map((table) => {
        const unknown = table.values.filter(
          (v) => !(meanings[table.field]?.[v.normalized] ?? null),
        ).length
        return (
          <section
            key={table.field}
            className="flex flex-col gap-3"
            aria-label={`${FIELD_LABEL[table.field] ?? table.field} values in ${columnLabel}`}
          >
            <h3 className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-base font-semibold text-ink-strong">
              {FIELD_LABEL[table.field] ?? table.field}
              <span className="text-sm font-normal text-muted">from {columnLabel}</span>
              {unknown > 0 && (
                <Badge tone="warning">
                  {unknown === 1 ? '1 value needs a meaning' : `${unknown} values need a meaning`}
                </Badge>
              )}
            </h3>
            <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
              {[...table.values]
                .sort((a, b) => {
                  const known = (v: typeof a) => (meanings[table.field]?.[v.normalized] ? 1 : 0)
                  return known(a) - known(b) || b.rows - a.rows
                })
                .map((value) => {
                  const chosen = meanings[table.field]?.[value.normalized] ?? ''
                  const isLikely =
                    chosen !== '' && chosen === value.suggested && value.confidence === 'likely'
                  return (
                    <li
                      key={value.normalized}
                      className="grid gap-2 p-3 md:grid-cols-[minmax(0,1fr)_minmax(14rem,22rem)] md:items-center"
                    >
                      <div className="min-w-0">
                        <p className="break-words text-sm font-semibold text-ink">“{value.raw}”</p>
                        <p className="text-xs text-muted">
                          {value.rows === 1 ? '1 row' : `${value.rows.toLocaleString()} rows`}
                          {value.hint ? ` · ${value.hint}` : ''}
                        </p>
                      </div>
                      <div className="flex flex-col gap-1">
                        <Select
                          aria-label={`Meaning of “${value.raw}”`}
                          value={chosen}
                          onChange={(event) =>
                            onChange(table.field, value.normalized, event.target.value || null)
                          }
                          aria-invalid={chosen === '' ? true : undefined}
                        >
                          <option value="">Choose a meaning…</option>
                          {(value.choices.length > 0
                            ? table.options.filter((o) => value.choices.includes(o.value))
                            : table.options
                          ).map((option) => (
                            <option key={option.value} value={option.value}>
                              {option.label}
                            </option>
                          ))}
                          {value.choices.length > 0 && (
                            <optgroup label="Other meanings">
                              {table.options
                                .filter((o) => !value.choices.includes(o.value))
                                .map((option) => (
                                  <option key={option.value} value={option.value}>
                                    {option.label}
                                  </option>
                                ))}
                            </optgroup>
                          )}
                        </Select>
                        {isLikely && (
                          <span className="text-xs font-semibold text-warning-strong">
                            Check this
                          </span>
                        )}
                      </div>
                    </li>
                  )
                })}
            </ul>
          </section>
        )
      })}
    </div>
  )
}
