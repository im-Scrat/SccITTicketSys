import { Button, SearchInput, Select } from '@/components/ui'
import type { TicketOptions } from '../types'

export type FilterKey =
  | 'status'
  | 'priority'
  | 'category'
  | 'technician'
  | 'breached'
  | 'awaiting_confirmation'
  | 'include_closed'
  | 'trashed'

interface TicketFiltersProps {
  search: string
  onSearch: (value: string) => void
  searchPlaceholder?: string
  params: URLSearchParams
  patch: (changes: Record<string, string | number | null>) => void
  options?: TicketOptions
  /** Which controls this surface offers — the feed shows far fewer than the directory. */
  show: FilterKey[]
  onClear: () => void
}

/**
 * The shared ticket filter bar.
 *
 * **Filter state lives in the URL**, not in component state — the same rule the
 * asset directory follows. A filtered view has to survive a refresh, a bookmark,
 * the back button and being pasted to a colleague, and none of those work if the
 * filters live in React. This component therefore reads from `params` and writes
 * through `patch`; it holds nothing of its own except the debounce buffer its
 * caller owns.
 *
 * An unknown slug is rejected by the API with 422 rather than ignored, so a chip
 * here can never claim to be filtering when it is not.
 */
export function TicketFilters({
  search,
  onSearch,
  searchPlaceholder = 'Search tickets…',
  params,
  patch,
  options,
  show,
  onClear,
}: TicketFiltersProps) {
  const has = (key: FilterKey) => show.includes(key)
  const active = countActiveFilters(params)

  return (
    <section className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6">
      <SearchInput
        value={search}
        onChange={onSearch}
        placeholder={searchPlaceholder}
        aria-label={searchPlaceholder}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {has('status') && (
          <FilterSelect
            label="Status"
            value={params.get('status') ?? ''}
            options={(options?.statuses ?? []).map((s) => ({ value: s.value, label: s.label }))}
            onChange={(value) => patch({ status: value || null, page: null })}
          />
        )}

        {has('priority') && (
          <FilterSelect
            label="Priority"
            value={params.get('priority') ?? ''}
            options={(options?.priorities ?? []).map((p) => ({ value: p.value, label: p.label }))}
            onChange={(value) => patch({ priority: value || null, page: null })}
          />
        )}

        {has('category') && (
          <FilterSelect
            label="Category"
            value={params.get('category') ?? ''}
            options={(options?.categories ?? []).map((c) => ({ value: c.value, label: c.label }))}
            onChange={(value) => patch({ category: value || null, page: null })}
          />
        )}

        {has('technician') && (
          <FilterSelect
            label="Assigned to"
            value={params.get('technician') ?? ''}
            options={[
              // A first-class value, not an empty one: "nobody is working this"
              // is the single most important thing an administrator filters for.
              { value: 'unassigned', label: 'Unassigned' },
              ...(options?.technicians ?? []).map((t) => ({ value: t.value, label: t.label })),
            ]}
            onChange={(value) => patch({ technician: value || null, page: null })}
          />
        )}

        {has('breached') && (
          <FilterSelect
            label="SLA"
            value={params.get('breached') ?? ''}
            options={[{ value: '1', label: 'Overdue only' }]}
            emptyLabel="Any"
            onChange={(value) => patch({ breached: value || null, page: null })}
          />
        )}

        {has('awaiting_confirmation') && (
          <FilterSelect
            label="Awaiting confirmation"
            value={params.get('awaiting_confirmation') ?? ''}
            options={[{ value: '1', label: 'Resolved, not yet confirmed' }]}
            emptyLabel="Any"
            onChange={(value) => patch({ awaiting_confirmation: value || null, page: null })}
          />
        )}

        {has('include_closed') && (
          <FilterSelect
            label="Closed tickets"
            value={params.get('include_closed') ?? ''}
            options={[{ value: '1', label: 'Include closed and cancelled' }]}
            emptyLabel="Open only"
            onChange={(value) => patch({ include_closed: value || null, page: null })}
          />
        )}

        {has('trashed') && (
          <FilterSelect
            label="Archived"
            value={params.get('trashed') ?? 'without'}
            options={[
              { value: 'without', label: 'Hide archived' },
              { value: 'with', label: 'Include archived' },
              { value: 'only', label: 'Archived only' },
            ]}
            allowEmpty={false}
            onChange={(value) => patch({ trashed: value === 'without' ? null : value, page: null })}
          />
        )}
      </div>

      {active > 0 && (
        <div className="flex flex-wrap items-center gap-4">
          <p className="text-sm text-muted">
            {active} filter{active === 1 ? '' : 's'} applied
          </p>
          <Button variant="ghost" size="sm" onClick={onClear}>
            Clear all filters
          </Button>
        </div>
      )}
    </section>
  )
}

function FilterSelect({
  label,
  value,
  options,
  onChange,
  allowEmpty = true,
  emptyLabel = 'All',
}: {
  label: string
  value: string
  options: Array<{ value: string; label: string }>
  onChange: (value: string) => void
  allowEmpty?: boolean
  emptyLabel?: string
}) {
  return (
    <label className="flex flex-col gap-2">
      <span className="text-sm font-semibold text-ink">{label}</span>
      <Select value={value} onChange={(event) => onChange(event.target.value)}>
        {allowEmpty && <option value="">{emptyLabel}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </label>
  )
}

/** Everything except paging, sorting and the tab counts as a filter. */
function countActiveFilters(params: URLSearchParams): number {
  const ignored = new Set(['page', 'sort', 'direction', 'tab', 'cursor'])
  let count = 0
  params.forEach((_value, key) => {
    if (!ignored.has(key)) count += 1
  })
  return count
}
