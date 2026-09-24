import { Button, SearchInput, Select } from '@/components/ui'
import type { MaintenanceOptions } from '../types'

export type FilterKey = 'status' | 'type' | 'preventive' | 'technician' | 'overdue' | 'trashed'

interface MaintenanceFiltersProps {
  search: string
  onSearch: (value: string) => void
  searchPlaceholder?: string
  params: URLSearchParams
  patch: (changes: Record<string, string | number | null>) => void
  options?: MaintenanceOptions
  /** Which controls this surface offers — the queue shows far fewer than the directory. */
  show: FilterKey[]
  onClear: () => void
}

/**
 * The shared maintenance filter bar.
 *
 * **Filter state lives in the URL**, not in component state — the rule the
 * ticket and asset directories already follow. A filtered view has to survive a
 * refresh, a bookmark, the back button and being pasted to a colleague, and none
 * of those work if the filters live in React. This component reads from `params`
 * and writes through `patch`; it holds nothing of its own.
 *
 * The `technician` control is offered only where the caller can act on it. A
 * technician filtering by technician could only be asking about someone else's
 * work, which the server's row scope refuses anyway — so the control would
 * describe something that cannot happen.
 *
 * An unknown slug is rejected by the API with 422 rather than ignored, so a
 * control here can never claim to be filtering when it is not.
 */
export function MaintenanceFilters({
  search,
  onSearch,
  searchPlaceholder = 'Search maintenance…',
  params,
  patch,
  options,
  show,
  onClear,
}: MaintenanceFiltersProps) {
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
            emptyLabel="All statuses"
            onChange={(value) => patch({ status: value || null, page: null })}
          />
        )}

        {has('type') && (
          <FilterSelect
            label="Type"
            value={params.get('type') ?? ''}
            options={(options?.types ?? []).map((t) => ({ value: t.value, label: t.label }))}
            emptyLabel="All types"
            onChange={(value) => patch({ type: value || null, page: null })}
          />
        )}

        {has('preventive') && (
          <FilterSelect
            label="Kind"
            value={params.get('preventive') ?? ''}
            options={[
              { value: '1', label: 'Preventive only' },
              { value: '0', label: 'Corrective only' },
            ]}
            emptyLabel="Preventive and corrective"
            onChange={(value) => patch({ preventive: value || null, page: null })}
          />
        )}

        {has('technician') && (
          <FilterSelect
            label="Technician"
            value={params.get('technician') ?? ''}
            options={(options?.technicians ?? []).map((t) => ({ value: t.value, label: t.label }))}
            emptyLabel="Everyone"
            onChange={(value) => patch({ technician: value || null, page: null })}
          />
        )}

        {has('overdue') && (
          <FilterSelect
            label="Due"
            value={params.get('overdue') ?? ''}
            options={[{ value: '1', label: 'Overdue only' }]}
            emptyLabel="Any date"
            onChange={(value) => patch({ overdue: value || null, page: null })}
          />
        )}

        {has('trashed') && (
          <FilterSelect
            label="Archived"
            value={params.get('trashed') ?? 'without'}
            options={[
              { value: 'without', label: 'Active only' },
              { value: 'with', label: 'Include archived' },
              { value: 'only', label: 'Archived only' },
            ]}
            allowEmpty={false}
            onChange={(value) => patch({ trashed: value || null, page: null })}
          />
        )}
      </div>

      {active > 0 && (
        <div>
          <Button variant="ghost" onClick={onClear}>
            Clear {active} filter{active === 1 ? '' : 's'}
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
  options: Array<{ value: string; label: string | null }>
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
            {option.label ?? option.value}
          </option>
        ))}
      </Select>
    </label>
  )
}

/** Everything except the search box and paging counts as a filter. */
function countActiveFilters(params: URLSearchParams): number {
  const keys = ['status', 'type', 'preventive', 'technician', 'overdue']
  let count = keys.filter((key) => (params.get(key) ?? '') !== '').length

  const trashed = params.get('trashed')
  if (trashed && trashed !== 'without') count += 1

  return count
}
