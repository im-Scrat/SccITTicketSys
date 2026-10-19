import { isAxiosError } from 'axios'
import { AlertTriangle, Info, XCircle } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Alert, Badge, Button, Checkbox, Drawer, Input, Select } from '@/components/ui'
import { cn } from '@/lib/cn'
import { useUpdateRow } from '../hooks/mutations'
import { ROW_STATUS_TONE, SEVERITY_LABEL } from '../lib/steps'
import type { AssetImport, ImportIssue, ImportRow, ImportTarget, Option } from '../types'
import { RoomCombobox } from './RoomCombobox'

const SEVERITY_ORDER: ImportIssue['severity'][] = [
  'error',
  'duplicate',
  'review',
  'warning',
  'info',
]
const DATE_TARGETS = ['purchase_date', 'warranty_expiration']

function readValue(values: Record<string, unknown>, key: string | null): string | null {
  if (!key || key.startsWith('location.') || key.startsWith('catalog.')) return null
  const value = key.startsWith('specification.')
    ? (values.specification as Record<string, unknown> | undefined)?.[key.slice(14)]
    : values[key]
  return value === null || value === undefined || value === '' ? null : String(value)
}

/**
 * One row's problems and values (Phase 2.10 R6, plan §3.4 RowIssuePanel).
 *
 * Problems first, each with what clears it. Then the values, grouped, each
 * "as saved" beside the cell it came from; only groups with a problem start
 * open. A correction is saved beside the original, never over it, and the row
 * is checked again on the server — the panel shows the server's verdict, not
 * its own guess.
 */
export function RowIssuePanel({
  item,
  row,
  onClose,
  onSaved,
  onGoTo,
}: {
  item: AssetImport
  row: ImportRow | null
  onClose: () => void
  onSaved: (row: ImportRow) => void
  onGoTo: (tab: 'locations' | 'catalog') => void
}) {
  return (
    <Drawer
      open={row !== null}
      onClose={onClose}
      size="lg"
      title={row ? `Row ${row.row_number}${row.entity_label ? ` · ${row.entity_label}` : ''}` : ''}
      description={
        row ? <Badge tone={ROW_STATUS_TONE[row.status]}>{row.status_label}</Badge> : undefined
      }
    >
      {row && (
        <RowEditor
          key={`${row.row_number}-${row.status}-${JSON.stringify(row.corrections)}`}
          item={item}
          row={row}
          onSaved={onSaved}
          onGoTo={onGoTo}
        />
      )}
    </Drawer>
  )
}

function RowEditor({
  item,
  row,
  onSaved,
  onGoTo,
}: {
  item: AssetImport
  row: ImportRow
  onSaved: (row: ImportRow) => void
  onGoTo: (tab: 'locations' | 'catalog') => void
}) {
  const update = useUpdateRow(item.id)
  const type = row.entity_type ?? 'pc_unit'
  const initialDrafts = useMemo(
    () =>
      Object.fromEntries(
        Object.entries(row.corrections)
          .filter(([k]) => k !== 'room')
          .map(([k, c]) => [k, c.value ?? '']),
      ),
    [row.corrections],
  )
  const [drafts, setDrafts] = useState<Record<string, string>>(initialDrafts)
  const [acknowledged, setAcknowledged] = useState<string[]>(row.acknowledged)
  const [room, setRoom] = useState<{ id: string; label: string; selectable: boolean } | null>(null)
  const [assignAnyway, setAssignAnyway] = useState(false)

  const issues = [...row.issues].sort(
    (a, b) => SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity),
  )
  const problemFields = useMemo(
    () => new Set(row.issues.filter((i) => i.severity !== 'info').map((i) => i.field)),
    [row.issues],
  )

  const applicable = item.targets.filter(
    (t) => t.payload[type] !== null || (item.entity_mode === 'mixed' && t.key === 'category'),
  )
  const groups = useMemo(() => {
    const out = new Map<string, ImportTarget[]>()
    for (const target of applicable) {
      const shown =
        target.key in row.original ||
        target.key in row.corrections ||
        readValue(row.values, target.payload[type]) !== null ||
        problemFields.has(target.key)
      if (!shown) continue
      out.set(target.group, [...(out.get(target.group) ?? []), target])
    }
    return [...out.entries()]
  }, [applicable, row, type, problemFields])

  const optionsFor = (target: ImportTarget): Option[] | null => {
    if (target.key === 'status')
      return type === 'pc_unit' ? item.default_options.pc_status : item.default_options.asset_status
    if (target.key === 'condition') return item.default_options.condition
    if (target.key === 'category') return item.default_options.category
    return null
  }

  const changed: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(drafts)) {
    if ((initialDrafts[key] ?? null) !== value) changed[key] = value
  }
  if (room) changed.room = { uuid: room.id, assign_anyway: assignAnyway }
  const ackChanged = acknowledged.slice().sort().join() !== row.acknowledged.slice().sort().join()
  const dirty = Object.keys(changed).length > 0 || ackChanged

  const errors = isAxiosError(update.error)
    ? Object.values(
        (update.error.response?.data as { errors?: Record<string, string[]>; message?: string })
          ?.errors ?? {},
      ).flat()
    : []

  const save = (extra: { exclude?: boolean } = {}) =>
    update.mutate(
      {
        rowNumber: row.row_number,
        change: {
          ...(Object.keys(changed).length > 0 ? { corrections: changed } : {}),
          ...(ackChanged ? { acknowledge: acknowledged } : {}),
          ...extra,
        },
      },
      { onSuccess: (result) => onSaved(result.data) },
    )

  return (
    <div className="flex flex-col gap-6">
      {row.previous_failure_code && (
        <Alert tone="warning" title="This row did not save last time">
          Fix what is listed below and confirm the import again.
        </Alert>
      )}

      <section aria-labelledby="row-problems" className="flex flex-col gap-3">
        <h3 id="row-problems" className="text-base font-semibold text-ink-strong">
          {issues.length === 0 ? 'No problems' : 'Problems'}
        </h3>
        {issues.length > 0 && (
          <ul className="flex flex-col gap-3">
            {issues.map((issue) => (
              <li
                key={`${issue.code}-${issue.field}`}
                className="flex gap-3 rounded-md border border-border p-3"
              >
                <SeverityIcon severity={issue.severity} />
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                  <p className="text-sm text-ink">
                    <span className="sr-only">{SEVERITY_LABEL[issue.severity]}: </span>
                    {issue.message}
                  </p>
                  {issue.action === 'acknowledge' && (
                    <Checkbox
                      label="I’ve checked this — save it as it is"
                      checked={acknowledged.includes(issue.code)}
                      onChange={(e) =>
                        setAcknowledged((current) =>
                          e.target.checked
                            ? [...current, issue.code]
                            : current.filter((c) => c !== issue.code),
                        )
                      }
                    />
                  )}
                  {issue.action === 'decide_location' && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="self-start"
                      onClick={() => onGoTo('locations')}
                    >
                      Decide in Locations
                    </Button>
                  )}
                  {issue.action === 'decide_catalog' && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="self-start"
                      onClick={() => onGoTo('catalog')}
                    >
                      Decide in Catalog
                    </Button>
                  )}
                  {issue.related.uuid !== undefined && typeof issue.related.label === 'string' && (
                    <p className="text-xs text-muted">Existing record: {issue.related.label}</p>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="row-values" className="flex flex-col gap-3">
        <h3 id="row-values" className="text-base font-semibold text-ink-strong">
          Values
        </h3>
        {groups.map(([group, targets]) => {
          const open = targets.some((t) => problemFields.has(t.key))
          return (
            <details key={group} open={open} className="rounded-md border border-border">
              <summary className="cursor-pointer px-3 py-2 text-sm font-semibold text-ink">
                {group}
              </summary>
              <ul className="flex flex-col divide-y divide-border">
                {targets.map((target) => {
                  const saved = readValue(row.values, target.payload[type])
                  const original = row.original[target.key] ?? null
                  const source = target.payload[type]
                    ? row.sources[target.payload[type] as string]
                    : undefined
                  const options = optionsFor(target)
                  const draft = drafts[target.key] ?? saved ?? original ?? ''
                  const inputId = `fix-${row.row_number}-${target.key}`
                  return (
                    <li
                      key={target.key}
                      className={cn(
                        'grid gap-2 p-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]',
                        problemFields.has(target.key) && 'bg-warning-subtle',
                      )}
                    >
                      <div className="min-w-0">
                        <label htmlFor={inputId} className="text-sm font-semibold text-ink">
                          {target.label}
                        </label>
                        <p className="break-words text-xs text-muted">
                          {original !== null ? `In your file: “${original}”` : 'Not in your file'}
                          {source === 'default'
                            ? ' · default'
                            : source === 'correction'
                              ? ' · corrected'
                              : ''}
                        </p>
                      </div>
                      {target.group === 'Location' ? (
                        <p className="self-center text-sm text-muted">
                          Decided per location, or choose this row’s room below.
                        </p>
                      ) : options ? (
                        <Select
                          id={inputId}
                          value={draft}
                          onChange={(e) => setDrafts({ ...drafts, [target.key]: e.target.value })}
                        >
                          <option value="">—</option>
                          {options.map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </Select>
                      ) : (
                        <Input
                          id={inputId}
                          type={DATE_TARGETS.includes(target.key) ? 'date' : 'text'}
                          value={draft}
                          onChange={(e) => setDrafts({ ...drafts, [target.key]: e.target.value })}
                        />
                      )}
                    </li>
                  )
                })}
              </ul>
            </details>
          )
        })}
      </section>

      <section aria-labelledby="row-room" className="flex flex-col gap-3">
        <h3 id="row-room" className="text-base font-semibold text-ink-strong">
          Room
        </h3>
        <p className="text-sm text-muted">
          {row.corrections.room
            ? 'This row has its own room.'
            : row.location
              ? `From the location “${row.location.source_text}”${row.location.room ? `: ${row.location.room}` : ' — not decided yet'}.`
              : 'No location.'}
        </p>
        <RoomCombobox
          label="This row’s room only"
          value={room?.id ?? null}
          selectedLabel={room?.label}
          onChange={(option) => {
            setRoom(option)
            setAssignAnyway(false)
          }}
          hint="Overrides the location decision for this row."
        />
        {room && !room.selectable && (
          <Checkbox
            label="This room is not in use. Assign it anyway"
            checked={assignAnyway}
            onChange={(e) => setAssignAnyway(e.target.checked)}
          />
        )}
      </section>

      {errors.length > 0 && (
        <Alert tone="error" title="Not saved">
          {errors.join(' ')}
        </Alert>
      )}

      <div className="flex flex-wrap justify-between gap-3 border-t border-border pt-4">
        <Button
          variant="ghost"
          size="sm"
          disabled={update.isPending}
          onClick={() => save({ exclude: !row.is_excluded })}
        >
          {row.is_excluded ? 'Include this row again' : 'Exclude this row'}
        </Button>
        <Button size="sm" disabled={!dirty || update.isPending} onClick={() => save()}>
          {update.isPending ? 'Checking…' : 'Save and recheck'}
        </Button>
      </div>
    </div>
  )
}

function SeverityIcon({ severity }: { severity: ImportIssue['severity'] }) {
  if (severity === 'error')
    return <XCircle size={20} aria-hidden="true" className="mt-0.5 shrink-0 text-warning-strong" />
  if (severity === 'info')
    return <Info size={20} aria-hidden="true" className="mt-0.5 shrink-0 text-muted" />
  return (
    <AlertTriangle size={20} aria-hidden="true" className="mt-0.5 shrink-0 text-warning-strong" />
  )
}
