import { ChevronLeft, ChevronRight, SlidersHorizontal } from 'lucide-react'
import { useId, useState, type ReactNode } from 'react'
import {
  Button,
  Checkbox,
  FilterChips,
  Input,
  Popover,
  Select,
  type FilterChip,
} from '@/components/ui'
import { cn } from '@/lib/cn'
import { canStepForward, isCalendarPeriod, stepLabel, stepPeriod } from '../lib/periods'
import type { ReportOptions, ReportParams, TicketOverviewMeta } from '../types'

interface ReportFilterToolbarProps {
  params: ReportParams
  patch: (changes: Partial<Record<keyof ReportParams, string | null>>) => void
  reset: () => void
  activeCount: number
  options?: ReportOptions
  meta?: TicketOverviewMeta
}

/**
 * The report filter toolbar (Phase 2.10): one row at large widths, so the
 * figures stay above the fold — Period with ‹ › stepping, Location, Status,
 * Compare, Reset. Below `md` it folds behind a "Filters (N)" button. Every
 * control writes to the URL (useReportFilters), and the chips row under it
 * lists whatever differs from the default.
 *
 * Every value it offers comes from the server's options and every range label
 * from the report's own meta, in the school's timezone — the browser's zone is
 * never asked what "this month" is.
 */
export function ReportFilterToolbar({
  params,
  patch,
  reset,
  activeCount,
  options,
  meta,
}: ReportFilterToolbarProps) {
  const [open, setOpen] = useState(false)
  const panelId = useId()
  const today = meta?.today ?? options?.today ?? ''
  const period = params.period ?? 'month'
  const calendar = isCalendarPeriod(period)
  const anchor = params.anchor ?? today

  const changePeriod = (value: string) => {
    const changes: Partial<Record<keyof ReportParams, string | null>> = {
      period: value === 'month' ? null : value,
      anchor: null,
      from: null,
      to: null,
      since_year: null,
      granularity: null,
    }

    if (value === 'custom' && meta) {
      // Start from the dates on screen, so switching to a custom range changes nothing yet.
      changes.from = meta.period.from
      changes.to = meta.period.to
    }

    if (value === 'since_year' && options) {
      changes.since_year = String(options.since_year.min)
      changes.compare = null
    }

    patch(changes)
  }

  const chips = buildChips(params, patch, options, meta)

  return (
    <div className="flex flex-col gap-3">
      <div className="md:hidden">
        <Button
          variant="secondary"
          size="sm"
          aria-expanded={open}
          aria-controls={panelId}
          leftIcon={<SlidersHorizontal size={16} aria-hidden="true" />}
          onClick={() => setOpen((current) => !current)}
        >
          Filters{activeCount > 0 ? ` (${activeCount})` : ''}
        </Button>
      </div>

      <div
        id={panelId}
        role="group"
        aria-label="Report filters"
        className={cn('flex-wrap items-end gap-x-4 gap-y-3', open ? 'flex' : 'hidden md:flex')}
      >
        <div className="flex w-full items-end gap-2 sm:w-auto">
          <label className="flex min-w-0 flex-1 flex-col gap-2 sm:w-72 sm:flex-none">
            <span className="text-sm font-semibold text-ink">Period</span>
            <Select value={period} onChange={(event) => changePeriod(event.target.value)}>
              {(options?.periods ?? [{ value: 'month', label: 'Month' }]).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.value === period && meta
                    ? `${option.label} · ${meta.period.range_label.replace(' (to date)', '')}`
                    : option.label}
                </option>
              ))}
            </Select>
          </label>
          {calendar && today !== '' && (
            <div className="flex gap-1">
              <StepButton
                label={stepLabel(period, -1)}
                onClick={() => patch({ anchor: stepPeriod(period, anchor, -1) })}
              >
                <ChevronLeft size={20} aria-hidden="true" />
              </StepButton>
              <StepButton
                label={stepLabel(period, 1)}
                disabled={!canStepForward(period, anchor, today)}
                onClick={() => {
                  const next = stepPeriod(period, anchor, 1)
                  // Back on the current period: drop the anchor, so the URL says "now".
                  patch({ anchor: canStepForward(period, next, today) ? next : null })
                }}
              >
                <ChevronRight size={20} aria-hidden="true" />
              </StepButton>
            </div>
          )}
        </div>

        {period === 'custom' && (
          <fieldset className="flex w-full flex-wrap items-end gap-2 sm:w-auto">
            <legend className="sr-only">Custom range</legend>
            <label className="flex flex-1 flex-col gap-2 sm:w-44 sm:flex-none">
              <span className="text-sm font-semibold text-ink">From</span>
              <Input
                type="date"
                min="2000-01-01"
                max={params.to ?? today}
                value={params.from ?? ''}
                onChange={(event) => patch({ from: event.target.value || null })}
              />
            </label>
            <label className="flex flex-1 flex-col gap-2 sm:w-44 sm:flex-none">
              <span className="text-sm font-semibold text-ink">To</span>
              <Input
                type="date"
                min={params.from ?? '2000-01-01'}
                max={today}
                value={params.to ?? ''}
                onChange={(event) => patch({ to: event.target.value || null })}
              />
            </label>
          </fieldset>
        )}

        {period === 'since_year' && options && (
          <label className="flex w-full flex-col gap-2 sm:w-36">
            <span className="text-sm font-semibold text-ink">Since</span>
            <Select
              value={params.since_year ?? String(options.since_year.min)}
              onChange={(event) => patch({ since_year: event.target.value })}
            >
              {yearsBetween(options.since_year.min, options.since_year.max).map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </Select>
          </label>
        )}

        {options && options.location_types.length > 0 && (
          <label className="flex w-full flex-col gap-2 sm:w-72">
            <span className="text-sm font-semibold text-ink">Location</span>
            <Select
              value={locationValue(params)}
              onChange={(event) => patch(locationChanges(event.target.value))}
            >
              <option value="">All locations</option>
              <option value="type:laboratory">All laboratories</option>
              <option value="type:office">All offices</option>
              {options.buildings.map((building) => {
                const rooms = options.rooms.filter((room) => room.building_id === building.id)
                if (rooms.length === 0) return null
                return (
                  <optgroup key={building.id} label={building.name}>
                    {rooms.map((room) => (
                      <option key={room.id} value={`room:${room.id}`}>
                        {room.name}
                        {room.archived ? ' (archived)' : ''}
                      </option>
                    ))}
                  </optgroup>
                )
              })}
            </Select>
          </label>
        )}

        {options && (
          <StatusPicker
            className="w-full sm:w-60"
            statuses={options.statuses}
            selected={params.status ? params.status.split(',') : []}
            onChange={(slugs) => patch({ status: slugs.length > 0 ? slugs.join(',') : null })}
          />
        )}

        {period !== 'since_year' && options && (
          <label className="flex w-full flex-col gap-2 sm:w-60">
            <span className="text-sm font-semibold text-ink">Compare with</span>
            <Select
              value={params.compare ?? 'previous'}
              onChange={(event) =>
                patch({ compare: event.target.value === 'previous' ? null : event.target.value })
              }
            >
              {options.comparisons.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </label>
        )}

        {activeCount > 0 && (
          <Button variant="ghost" onClick={reset}>
            Reset
          </Button>
        )}
      </div>

      <FilterChips chips={chips} />
    </div>
  )
}

/** A square ‹ / › button the height of the selects beside it. */
function StepButton({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string
  disabled?: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className="flex h-15 w-12 items-center justify-center rounded-md border-2 border-control-border bg-surface text-ink transition-colors hover:bg-surface-sunken disabled:cursor-not-allowed disabled:border-border disabled:text-faint disabled:hover:bg-surface"
    >
      {children}
    </button>
  )
}

function StatusPicker({
  statuses,
  selected,
  onChange,
  className,
}: {
  statuses: ReportOptions['statuses']
  selected: string[]
  onChange: (slugs: string[]) => void
  className?: string
}) {
  const openSlugs = statuses.filter((s) => s.is_open).map((s) => s.value)
  const finishedSlugs = statuses.filter((s) => s.is_terminal).map((s) => s.value)
  const same = (a: string[], b: string[]) => a.length === b.length && a.every((v) => b.includes(v))

  const summary =
    selected.length === 0
      ? 'All statuses'
      : same(selected, openSlugs)
        ? 'Still open'
        : same(selected, finishedSlugs)
          ? 'Finished'
          : selected.length <= 2
            ? statuses
                .filter((s) => selected.includes(s.value))
                .map((s) => s.label)
                .join(', ')
            : `${selected.length} statuses`

  const toggle = (slug: string) =>
    onChange(
      selected.includes(slug) ? selected.filter((value) => value !== slug) : [...selected, slug],
    )

  return (
    <Popover label="Status" value={summary} className={className}>
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" size="sm" onClick={() => onChange([])}>
            All
          </Button>
          <Button variant="secondary" size="sm" onClick={() => onChange(openSlugs)}>
            Still open
          </Button>
          <Button variant="secondary" size="sm" onClick={() => onChange(finishedSlugs)}>
            Finished
          </Button>
        </div>
        <div className="flex flex-col gap-2">
          {statuses.map((status) => (
            <Checkbox
              key={status.value}
              label={status.label}
              checked={selected.includes(status.value)}
              onChange={() => toggle(status.value)}
            />
          ))}
        </div>
      </div>
    </Popover>
  )
}

function locationValue(params: ReportParams): string {
  if (params.room) return `room:${params.room}`
  if (params.room_type) return `type:${params.room_type}`
  return ''
}

function locationChanges(value: string): Partial<Record<keyof ReportParams, string | null>> {
  const [kind, id] = value.split(':')
  return {
    room: kind === 'room' ? id : null,
    room_type: kind === 'type' ? id : null,
    building: null,
  }
}

function yearsBetween(min: number, max: number): string[] {
  const years: string[] = []
  for (let year = max; year >= min; year--) years.push(String(year))
  return years
}

function buildChips(
  params: ReportParams,
  patch: ReportFilterToolbarProps['patch'],
  options?: ReportOptions,
  meta?: TicketOverviewMeta,
): FilterChip[] {
  const chips: FilterChip[] = []

  if ((params.period && params.period !== 'month') || params.anchor) {
    chips.push({
      key: 'period',
      label: meta ? `Period: ${meta.period.range_label}` : 'Period',
      onRemove: () =>
        patch({
          period: null,
          anchor: null,
          from: null,
          to: null,
          since_year: null,
          granularity: null,
        }),
    })
  }

  if (params.compare) {
    const label = options?.comparisons.find((c) => c.value === params.compare)?.label
    chips.push({
      key: 'compare',
      label: `Compare: ${label ?? params.compare}`,
      onRemove: () => patch({ compare: null }),
    })
  }

  if (params.room_type) {
    chips.push({
      key: 'room_type',
      label: params.room_type === 'laboratory' ? 'Laboratories' : 'Offices',
      onRemove: () => patch({ room_type: null }),
    })
  }

  if (params.room || params.building) {
    chips.push({
      key: 'location',
      label: meta?.filters.room?.label ?? meta?.filters.building?.name ?? 'Selected location',
      onRemove: () => patch({ room: null, building: null }),
    })
  }

  if (params.status) {
    const labels = params.status
      .split(',')
      .map((slug) => options?.statuses.find((s) => s.value === slug)?.label ?? slug)
    chips.push({
      key: 'status',
      label: `Status: ${labels.join(', ')}`,
      onRemove: () => patch({ status: null }),
    })
  }

  return chips
}
