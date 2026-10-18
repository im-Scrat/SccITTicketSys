import { isAxiosError } from 'axios'
import { BookOpen } from 'lucide-react'
import { useState } from 'react'
import { Alert, Badge, Button, Combobox, EmptyState, Field, Input, Skeleton } from '@/components/ui'
import { useAssetCatalog } from '@/features/assets/hooks/queries'
import { useDecideCatalog } from '../hooks/mutations'
import { useImportCatalog } from '../hooks/queries'
import type { CatalogEntry } from '../types'

const MATCH_TONE = {
  existing: 'success',
  new: 'primary',
  suggested: 'warning',
  blocked: 'warning',
} as const

/**
 * Catalog tab (Phase 2.10 R6, owner decision D5): every category, brand and
 * model combination the file uses that is not already in the catalog. Each
 * new one needs the administrator's approval — with its names corrected if
 * need be — or an existing model, or a decision not to import its rows.
 * Nothing is added to the catalog until the import is confirmed.
 */
export function CatalogProposalList({ importId }: { importId: string }) {
  const entries = useImportCatalog(importId)

  if (entries.isLoading) return <Skeleton className="h-40 w-full" />
  if (!entries.data || entries.data.length === 0) {
    return (
      <EmptyState
        icon={<BookOpen size={32} aria-hidden="true" />}
        title="No catalog entries"
        description="This file has no other equipment to match."
      />
    )
  }

  const pending = entries.data.filter((e) => e.decision === 'pending').length

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-muted">
        {pending === 0
          ? 'Every catalog entry has a decision.'
          : pending === 1
            ? '1 entry needs a decision.'
            : `${pending} entries need a decision.`}{' '}
        Approved entries are added to the catalog only when you confirm the import.
      </p>
      <ul className="flex flex-col gap-4">
        {entries.data.map((entry) => (
          <li key={entry.id}>
            <CatalogCard importId={importId} entry={entry} />
          </li>
        ))}
      </ul>
    </div>
  )
}

function CatalogCard({ importId, entry }: { importId: string; entry: CatalogEntry }) {
  const decide = useDecideCatalog(importId)
  const [names, setNames] = useState({
    manufacturer_name: entry.manufacturer_name ?? '',
    model_name: entry.model_name,
    model_number: entry.model_number ?? '',
  })
  const [modelId, setModelId] = useState<string | null>(
    entry.chosen_model ? String(entry.chosen_model.id) : null,
  )
  const [choosing, setChoosing] = useState(entry.match === 'suggested' || entry.match === 'blocked')
  const catalog = useAssetCatalog(choosing)
  const error = isAxiosError(decide.error)
    ? (Object.values(
        (decide.error.response?.data as { errors?: Record<string, string[]> })?.errors ?? {},
      )[0]?.[0] ?? 'The decision was not saved.')
    : null

  const sameType = (catalog.data?.models ?? []).filter((m) => m.category === entry.component_type)
  const options = [
    ...entry.suggestions
      .filter((s) => !s.archived)
      .map((s) => ({ value: String(s.model_id), label: s.label, group: 'Suggested' })),
    ...sameType
      .filter((m) => !entry.suggestions.some((s) => s.model_id === m.value))
      .map((m) => ({
        value: String(m.value),
        label: [m.manufacturer, m.label].filter(Boolean).join(' '),
        group: 'In the catalog',
      })),
  ]

  const title = [entry.component_label, entry.manufacturer_name, entry.model_name]
    .filter(Boolean)
    .join(' · ')
  const headingId = `cat-${entry.id}`
  const rows = entry.row_count === 1 ? '1 row' : `${entry.row_count.toLocaleString()} rows`

  if (entry.match === 'existing' && entry.decision === 'approved') {
    return (
      <article
        aria-labelledby={headingId}
        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-4"
      >
        <div className="min-w-0">
          <h3 id={headingId} className="break-words text-base font-semibold text-ink-strong">
            {title}
          </h3>
          <p className="text-sm text-muted">
            {rows} · {entry.matched_model?.label ?? 'In the catalog'}
          </p>
        </div>
        <Badge tone="success">{entry.match_label}</Badge>
      </article>
    )
  }

  return (
    <article
      aria-labelledby={headingId}
      className="flex flex-col gap-4 rounded-md border border-border p-4"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 id={headingId} className="break-words text-base font-semibold text-ink-strong">
            {title}
          </h3>
          <p className="text-sm text-muted">{rows}</p>
        </div>
        <Badge
          tone={
            entry.decision === 'pending'
              ? MATCH_TONE[entry.match]
              : entry.decision === 'rejected'
                ? 'outline'
                : 'success'
          }
        >
          {entry.decision === 'pending' ? entry.match_label : entry.decision_label}
        </Badge>
      </div>

      {entry.match === 'blocked' && (
        <Alert tone="warning">
          This model is archived in the catalog. Use an existing model instead, or don’t import
          these rows.
        </Alert>
      )}
      {entry.decision === 'existing' && entry.chosen_model && (
        <p className="text-sm text-ink">
          Saved against <span className="font-semibold">{entry.chosen_model.label}</span>.
        </p>
      )}

      {!choosing && entry.match !== 'blocked' && (
        <div className="grid gap-4 md:grid-cols-3">
          <Field label="Manufacturer">
            <Input
              value={names.manufacturer_name}
              onChange={(e) => setNames({ ...names, manufacturer_name: e.target.value })}
            />
          </Field>
          <Field label="Model" required>
            <Input
              value={names.model_name}
              onChange={(e) => setNames({ ...names, model_name: e.target.value })}
            />
          </Field>
          <Field label="Model number">
            <Input
              value={names.model_number}
              onChange={(e) => setNames({ ...names, model_number: e.target.value })}
            />
          </Field>
        </div>
      )}

      {choosing && (
        <Combobox
          label={`Existing ${entry.component_label} model`}
          value={modelId}
          onChange={setModelId}
          options={options}
          loading={catalog.isLoading}
          placeholder="Search the catalog"
          emptyText="No model of this category in the catalog"
        />
      )}

      <div className="flex flex-wrap gap-3">
        {!choosing && entry.match !== 'blocked' ? (
          <>
            <Button
              size="sm"
              variant="secondary"
              disabled={decide.isPending || names.model_name.trim() === ''}
              onClick={() =>
                decide.mutate({ entry: entry.id, decision: { decision: 'approved', ...names } })
              }
            >
              Approve as a new entry
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setChoosing(true)}>
              Use an existing model
            </Button>
          </>
        ) : (
          <>
            <Button
              size="sm"
              variant="secondary"
              disabled={decide.isPending || modelId === null}
              onClick={() =>
                decide.mutate({
                  entry: entry.id,
                  decision: { decision: 'existing', model: Number(modelId) },
                })
              }
            >
              Use this model
            </Button>
            {entry.match !== 'blocked' && (
              <Button size="sm" variant="ghost" onClick={() => setChoosing(false)}>
                Add it as a new entry instead
              </Button>
            )}
          </>
        )}
        <Button
          size="sm"
          variant="ghost"
          disabled={decide.isPending}
          onClick={() => decide.mutate({ entry: entry.id, decision: { decision: 'rejected' } })}
        >
          Don’t import these rows
        </Button>
      </div>
      {error && <Alert tone="error">{error}</Alert>}
    </article>
  )
}
