import { isAxiosError } from 'axios'
import { Check, X } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Checkbox,
  Field,
  Input,
  Select,
  TruncatedText,
} from '@/components/ui'
import { cn } from '@/lib/cn'
import { useUpdateMapping, useUpdateSource } from '../hooks/mutations'
import type { AssetImport, ImportColumn, ImportTarget } from '../types'
import { ImportActionBar } from './ImportActionBar'
import { ImportPanel } from './ImportPanel'
import { ValueMeaningTable } from './ValueMeaningTable'

/** '' = not decided yet (an ambiguous column); NONE = deliberately not imported. */
const NONE = '__none__'
const MEANING_TARGETS = ['status', 'condition', 'category']
const DATE_TARGETS = ['purchase_date', 'warranty_expiration']

function initialChoice(item: AssetImport, column: ImportColumn): string {
  const mapped = item.mapping[String(column.index)]
  if (mapped) return mapped
  return column.confidence === 'ambiguous' ? '' : NONE
}

function confidenceBadge(column: ImportColumn, choice: string) {
  const original = column.target ?? ''
  if (choice !== (original || (column.confidence === 'ambiguous' ? '' : NONE))) {
    return <Badge tone="neutral">Your choice</Badge>
  }
  switch (column.confidence) {
    case 'exact':
    case 'synonym':
      return (
        <Badge tone="success" icon={<Check size={16} aria-hidden="true" />}>
          Matched
        </Badge>
      )
    case 'likely':
      return <Badge tone="warning">Check this</Badge>
    case 'ambiguous':
      return <Badge tone="warning">Choose one</Badge>
    default:
      return <Badge tone="outline">Not imported</Badge>
  }
}

/**
 * Map columns (Phase 2.10 R6, plan §3.4): what each column of the file holds,
 * what its values mean, and the defaults for empty cells. "Check rows" stays
 * disabled, with its reason in words, until the mapping is acceptable; the
 * server applies the same rules and answers in the same words.
 */
export function MappingStep({ item }: { item: AssetImport }) {
  const updateMapping = useUpdateMapping(item.id)
  const updateSource = useUpdateSource(item.id)

  const [choices, setChoices] = useState<Record<string, string>>(() =>
    Object.fromEntries(item.columns.map((c) => [String(c.index), initialChoice(item, c)])),
  )
  const [meanings, setMeanings] = useState<Record<string, Record<string, string | null>>>(() =>
    structuredClone(item.value_mappings ?? {}),
  )
  const [defaults, setDefaults] = useState<Record<string, string | boolean>>(() => ({
    ...item.defaults,
  }))
  const [dateOrder, setDateOrder] = useState<'dmy' | 'mdy' | null>(
    item.date_order ?? item.date_summary?.evidence ?? null,
  )
  const [source, setSource] = useState({
    sheet: item.source.sheet ?? '',
    header_row: item.source.header_row ?? 1,
    delimiter: item.source.delimiter ?? '',
    encoding: item.source.encoding ?? '',
  })

  const targetsByKey = useMemo(() => new Map(item.targets.map((t) => [t.key, t])), [item.targets])
  const groups = useMemo(() => {
    const out = new Map<string, ImportTarget[]>()
    for (const target of item.targets)
      out.set(target.group, [...(out.get(target.group) ?? []), target])
    return [...out.entries()]
  }, [item.targets])

  const chosenTargets = Object.values(choices).filter((c) => c !== '' && c !== NONE)
  const duplicates = chosenTargets.filter((t, i) => chosenTargets.indexOf(t) !== i)
  const undecided = item.columns.filter((c) => choices[String(c.index)] === '')
  const required = item.targets.filter((t) => t.required)
  const missing = required.filter(
    (t) => !chosenTargets.includes(t.key) && !(t.key === 'category' && defaults.category),
  )
  const datesMapped = chosenTargets.some((t) => DATE_TARGETS.includes(t))
  const needsDateOrder = datesMapped && (item.date_summary?.required ?? false) && dateOrder === null

  const reason =
    missing.length > 0
      ? `Choose a column for ${missing.map((t) => t.label).join(', ')}.`
      : undecided.length > 0
        ? `Choose what column ${undecided[0].letter}, “${undecided[0].header}”, holds — or Not imported.`
        : duplicates.length > 0
          ? `${targetsByKey.get(duplicates[0])?.label} is chosen for two columns.`
          : needsDateOrder
            ? 'Choose how this file writes dates.'
            : null

  const onPrefill = useCallback((field: string, proposals: Record<string, string>) => {
    setMeanings((current) => {
      const existing = current[field] ?? {}
      const merged = { ...proposals, ...existing }
      return { ...current, [field]: merged }
    })
  }, [])

  const errors = isAxiosError(updateMapping.error)
    ? Object.values(
        (updateMapping.error.response?.data as { errors?: Record<string, string[]> } | undefined)
          ?.errors ?? {},
      ).flat()
    : []
  const conflict =
    isAxiosError(updateMapping.error) && updateMapping.error.response?.status === 409
      ? (updateMapping.error.response.data as { message?: string }).message
      : null

  const submit = () => {
    const mapping: Record<string, string | null> = {}
    for (const [index, choice] of Object.entries(choices))
      mapping[index] = choice === '' || choice === NONE ? null : choice
    updateMapping.mutate({
      mapping,
      value_mappings: meanings,
      defaults: Object.fromEntries(
        Object.entries(defaults).filter(([, v]) => v !== '' && v !== false),
      ),
      date_order: datesMapped ? dateOrder : null,
    })
  }

  const isCsv = item.file.extension === 'csv'
  const meaningColumns = item.columns.filter((c) =>
    MEANING_TARGETS.includes(choices[String(c.index)]),
  )
  const unmapped = item.columns.filter((c) => choices[String(c.index)] === NONE)

  return (
    <div className="flex flex-col gap-8">
      {item.source.template_match && (
        <Alert tone="success" title="All columns matched the template">
          Check the values below, then check the rows.
        </Alert>
      )}

      <ImportPanel
        id="import-source"
        title="Where the data is"
        description={`${item.columns.length} columns, ${item.total_rows.toLocaleString()} rows below the header row.`}
      >
        <div className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {item.source.sheets.length > 1 && (
              <Field label="Sheet">
                <Select
                  value={source.sheet}
                  onChange={(e) => setSource({ ...source, sheet: e.target.value })}
                >
                  {item.source.sheets.map((sheet) => (
                    <option key={sheet} value={sheet}>
                      {sheet}
                    </option>
                  ))}
                </Select>
              </Field>
            )}
            <Field label="Header row" hint="The row that names the columns.">
              <Input
                type="number"
                min={1}
                value={source.header_row}
                onChange={(e) => setSource({ ...source, header_row: Number(e.target.value) || 1 })}
              />
            </Field>
            {isCsv && (
              <>
                <Field label="Separator">
                  <Select
                    value={source.delimiter}
                    onChange={(e) => setSource({ ...source, delimiter: e.target.value })}
                  >
                    <option value=",">Comma ( , )</option>
                    <option value=";">Semicolon ( ; )</option>
                    <option value="tab">Tab</option>
                    <option value="|">Bar ( | )</option>
                  </Select>
                </Field>
                <Field label="Text encoding">
                  <Select
                    value={source.encoding}
                    onChange={(e) => setSource({ ...source, encoding: e.target.value })}
                  >
                    {['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1'].map((enc) => (
                      <option key={enc} value={enc}>
                        {enc}
                      </option>
                    ))}
                  </Select>
                </Field>
              </>
            )}
          </div>
          <div>
            <Button
              variant="secondary"
              size="sm"
              disabled={updateSource.isPending}
              onClick={() =>
                updateSource.mutate({
                  sheet: source.sheet || null,
                  header_row: source.header_row,
                  delimiter: isCsv ? source.delimiter || null : null,
                  encoding: isCsv ? source.encoding || null : null,
                })
              }
            >
              Read the file again
            </Button>
            <p className="mt-2 text-xs text-muted">
              Reading again replaces the rows and any decisions made on them.
            </p>
          </div>
        </div>
      </ImportPanel>

      <div className="grid gap-8 xl:grid-cols-12">
        <ImportPanel
          id="import-columns"
          title="Columns"
          description="What each column holds, and where it is saved."
          className="xl:col-span-8"
        >
          <ul className="flex flex-col divide-y divide-border">
            {item.columns.map((column) => {
              const key = String(column.index)
              const choice = choices[key]
              const target = targetsByKey.get(choice)
              return (
                <li
                  key={key}
                  className="grid gap-3 py-4 first:pt-0 last:pb-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(16rem,1.2fr)]"
                >
                  <div className="min-w-0">
                    <p className="text-xs font-semibold text-muted">Column {column.letter}</p>
                    <p className="break-words text-base font-semibold text-ink-strong">
                      {column.header}
                    </p>
                    {column.never_imported && (
                      <p className="mt-1 text-xs text-muted">{column.never_imported}</p>
                    )}
                    {column.quantity && (
                      <p className="mt-1 text-xs text-muted">
                        One row creates one record; rows with a quantity above 1 are held for
                        review.
                      </p>
                    )}
                  </div>
                  <ul
                    aria-label={`Examples from column ${column.letter}`}
                    className="flex min-w-0 flex-col gap-1 text-sm text-muted"
                  >
                    {column.samples.length === 0 ? (
                      <li>No values</li>
                    ) : (
                      column.samples.map((sample, i) => (
                        <li key={i} className="min-w-0">
                          <TruncatedText text={sample} />
                        </li>
                      ))
                    )}
                  </ul>
                  <div className="flex min-w-0 flex-col gap-2">
                    <Select
                      aria-label={`Import column ${column.letter}, ${column.header}, as`}
                      value={choice}
                      aria-invalid={choice === '' || duplicates.includes(choice) ? true : undefined}
                      onChange={(e) => setChoices({ ...choices, [key]: e.target.value })}
                    >
                      {choice === '' && <option value="">Choose one…</option>}
                      <option value={NONE}>Not imported</option>
                      {column.alternatives.length > 0 && (
                        <optgroup label="Most likely">
                          {column.alternatives.map((alt) => (
                            <option key={`alt-${alt}`} value={alt}>
                              {targetsByKey.get(alt)?.label ?? alt}
                            </option>
                          ))}
                        </optgroup>
                      )}
                      {groups.map(([group, targets]) => (
                        <optgroup key={group} label={group}>
                          {targets
                            .filter((t) => !column.alternatives.includes(t.key))
                            .map((t) => (
                              <option key={t.key} value={t.key}>
                                {t.label}
                                {t.required ? ' (required)' : ''}
                              </option>
                            ))}
                        </optgroup>
                      ))}
                    </Select>
                    <div className="flex flex-wrap items-center gap-2">
                      {confidenceBadge(column, choice)}
                      {target && <span className="text-xs text-muted">{target.destination}</span>}
                    </div>
                    {target?.hint && <p className="text-xs text-muted">{target.hint}</p>}
                  </div>
                </li>
              )
            })}
          </ul>
        </ImportPanel>

        <aside className="flex flex-col gap-8 xl:col-span-4 xl:sticky xl:top-[6.5rem] xl:self-start">
          <ImportPanel id="import-required" title="Required">
            <ul className="flex flex-col gap-2">
              {required.map((t) => {
                const done = !missing.includes(t)
                return (
                  <li key={t.key} className="flex items-center gap-2 text-sm">
                    {done ? (
                      <Check
                        size={20}
                        aria-hidden="true"
                        className="shrink-0 text-success-strong"
                      />
                    ) : (
                      <X size={20} aria-hidden="true" className="shrink-0 text-warning-strong" />
                    )}
                    <span className={cn(done ? 'text-ink' : 'font-semibold text-warning-strong')}>
                      {t.label}
                      <span className="sr-only">
                        {done ? ': has a column' : ': needs a column'}
                      </span>
                    </span>
                  </li>
                )
              })}
            </ul>
            {item.entity_mode === 'mixed' && (
              <p className="mt-3 text-xs text-muted">
                Each row also needs what its own kind requires: a unit code and name for a PC unit;
                an asset tag and model for other equipment.
              </p>
            )}
          </ImportPanel>

          {datesMapped && (
            <ImportPanel id="import-dates" title="How dates are written">
              <fieldset className="flex flex-col gap-3">
                <legend className="text-sm text-muted">
                  {item.date_summary?.required
                    ? `${item.date_summary.ambiguous.toLocaleString()} dates could be read either way.`
                    : 'Choose if any dates are written as numbers with slashes.'}
                </legend>
                {(['dmy', 'mdy'] as const).map((order) => (
                  <label
                    key={order}
                    className="flex cursor-pointer items-start gap-3 text-sm text-ink"
                  >
                    <input
                      type="radio"
                      name="date_order"
                      checked={dateOrder === order}
                      onChange={() => setDateOrder(order)}
                      className="mt-1 size-5 accent-[var(--primary)]"
                    />
                    <span>
                      <span className="font-semibold">
                        {order === 'dmy' ? 'Day first' : 'Month first'}
                      </span>
                      {item.date_summary?.samples.map((sample) => (
                        <span key={sample.raw} className="block text-xs text-muted">
                          {sample.raw} → {formatIso(order === 'dmy' ? sample.dmy : sample.mdy)}
                        </span>
                      ))}
                    </span>
                  </label>
                ))}
                {item.date_summary?.evidence && (
                  <p className="text-xs text-muted">
                    Dates in the file show they are written{' '}
                    {item.date_summary.evidence === 'dmy' ? 'day first' : 'month first'}.
                  </p>
                )}
              </fieldset>
            </ImportPanel>
          )}

          <ImportPanel
            id="import-defaults"
            title="Defaults for empty cells"
            description="Used only where the file has no value."
          >
            <div className="flex flex-col gap-4">
              {item.entity_mode !== 'pc_units' && (
                <Field label="Category">
                  <Select
                    value={String(defaults.category ?? '')}
                    onChange={(e) => setDefaults({ ...defaults, category: e.target.value })}
                  >
                    <option value="">No default</option>
                    {item.default_options.category.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              )}
              {item.entity_mode !== 'assets' && (
                <Field label="PC unit status">
                  <Select
                    value={String(defaults.pc_status ?? '')}
                    onChange={(e) => setDefaults({ ...defaults, pc_status: e.target.value })}
                  >
                    <option value="">The app’s default (Available)</option>
                    {item.default_options.pc_status.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              )}
              {item.entity_mode !== 'pc_units' && (
                <Field label="Asset status">
                  <Select
                    value={String(defaults.asset_status ?? '')}
                    onChange={(e) => setDefaults({ ...defaults, asset_status: e.target.value })}
                  >
                    <option value="">The app’s default (New)</option>
                    {item.default_options.asset_status.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              )}
              <Field label="Condition">
                <Select
                  value={String(defaults.condition ?? '')}
                  onChange={(e) => setDefaults({ ...defaults, condition: e.target.value })}
                >
                  <option value="">The app’s default (Working)</option>
                  {item.default_options.condition.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </Field>
              {item.source.sheets.length > 0 && (
                <Checkbox
                  label="Use the sheet’s name as the location for rows without one"
                  checked={Boolean(defaults.location_from_sheet_name)}
                  onChange={(e) =>
                    setDefaults({ ...defaults, location_from_sheet_name: e.target.checked })
                  }
                />
              )}
            </div>
          </ImportPanel>
        </aside>
      </div>

      {meaningColumns.length > 0 && (
        <ImportPanel
          id="import-meanings"
          title="What the values mean"
          description="Each distinct value, with how many rows use it."
        >
          <div className="flex flex-col gap-8">
            {meaningColumns.map((column) => (
              <ValueMeaningTable
                key={`${column.index}-${choices[String(column.index)]}`}
                importId={item.id}
                column={column.index}
                columnLabel={`column ${column.letter}, “${column.header}”`}
                target={choices[String(column.index)]}
                initial={
                  item.mapping[String(column.index)] === choices[String(column.index)]
                    ? item.meaning_tables.filter((t) => t.column === column.index)
                    : undefined
                }
                meanings={meanings}
                onChange={(field, normalized, meaning) =>
                  setMeanings((current) => ({
                    ...current,
                    [field]: { ...(current[field] ?? {}), [normalized]: meaning },
                  }))
                }
                onPrefill={onPrefill}
              />
            ))}
          </div>
        </ImportPanel>
      )}

      {unmapped.length > 0 && (
        <details className="rounded-lg border-2 border-border bg-surface p-4 sm:p-6">
          <summary className="cursor-pointer text-base font-semibold text-ink-strong">
            Columns not imported ({unmapped.length})
          </summary>
          <ul className="mt-3 flex flex-col gap-1 text-sm text-muted">
            {unmapped.map((c) => (
              <li key={c.index}>
                Column {c.letter}, “{c.header}”{c.never_imported ? ` — ${c.never_imported}` : ''}
              </li>
            ))}
          </ul>
        </details>
      )}

      {(errors.length > 0 || conflict) && (
        <Alert tone="error" title="The mapping was not saved">
          <ul className="list-disc pl-5">
            {conflict && <li>{conflict}</li>}
            {errors.map((e) => (
              <li key={e}>{e}</li>
            ))}
          </ul>
        </Alert>
      )}

      <ImportActionBar reason={reason}>
        <Button onClick={submit} disabled={reason !== null || updateMapping.isPending}>
          {updateMapping.isPending ? 'Saving…' : 'Check rows'}
        </Button>
      </ImportActionBar>
    </div>
  )
}

function formatIso(value: string | null): string {
  if (!value) return 'not a real date'
  return new Date(`${value}T00:00:00`).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}
