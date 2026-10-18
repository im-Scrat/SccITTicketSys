import { Info } from 'lucide-react'
import { Alert, Select, StatCard } from '@/components/ui'
import { drilldownHref } from '../../lib/drilldown'
import type { OverallParams, OverallSection, OverallSectionResponse } from '../../types'
import { RecordsTable } from './RecordsTable'
import { SectionChartPanel } from './SectionCharts'
import { SectionTable } from './SectionTable'

interface SectionViewProps {
  section: OverallSection
  meta: OverallSectionResponse['meta']
  params: OverallParams
  onGroupChange: (group: string) => void
}

/**
 * One Overall Reports section, laid out from the server's description (the
 * same description its documents use): purpose, the filters that do not apply
 * here, at most four headline figures, charts (or one compact panel saying
 * there is too little data to chart), tables, record tables, and how each
 * figure is counted.
 */
export function SectionView({ section, meta, params, onGroupChange }: SectionViewProps) {
  const chartedTables = new Set(section.charts.map((c) => c.table_key).filter(Boolean))

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-2">
        <h2 className="text-xl font-semibold text-ink-strong">{section.title}</h2>
        {section.intro && <p className="max-w-[70ch] text-sm text-muted">{section.intro}</p>}
        {section.not_applicable.map((note) => (
          <p key={note} className="flex items-start gap-2 text-sm text-ink">
            <Info size={16} aria-hidden="true" className="mt-1 shrink-0 text-muted" />
            {note}
          </p>
        ))}
      </header>

      {section.kpis.length > 0 && (
        <div className="@container">
          <ul className="grid grid-cols-1 gap-3 @md:grid-cols-2 @3xl:grid-cols-4">
            {section.kpis.map((kpi) => (
              <li key={kpi.label} className="min-w-0">
                <StatCard
                  label={kpi.label}
                  value={kpi.value}
                  hint={kpi.detail ?? undefined}
                  to={drilldownHref(kpi.drilldown)}
                />
              </li>
            ))}
          </ul>
        </div>
      )}

      {section.key === 'locations' && (
        <label className="flex max-w-xs flex-col gap-2">
          <span className="text-sm font-semibold text-ink">Group problems by</span>
          <Select
            value={params.location_group ?? 'room'}
            onChange={(e) => onGroupChange(e.target.value)}
          >
            <option value="room">Room</option>
            <option value="floor">Floor</option>
            <option value="building">Building</option>
          </Select>
        </label>
      )}

      {section.insufficient && (
        <Alert tone="info" title="Too little data to chart">
          {section.insufficient}
        </Alert>
      )}

      {section.charts.length > 0 && (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
          {section.charts.map((chart) => (
            <div key={chart.title} className="flex min-w-0 flex-col [&>section]:flex-1">
              <SectionChartPanel chart={chart} />
            </div>
          ))}
        </div>
      )}

      <div className="grid grid-cols-1 gap-8 xl:grid-cols-2">
        {section.tables.map((table) => (
          <div
            key={table.key}
            className={
              table.columns.length > 4 || table.rows.length > 12
                ? 'min-w-0 xl:col-span-2'
                : 'min-w-0'
            }
          >
            <SectionTable
              title={table.title}
              columns={table.columns}
              rows={table.rows}
              links={table.links}
              note={
                [table.note, table.truncation_note].filter(Boolean).join(' ') ||
                (chartedTables.has(table.key) ? 'The figures behind the chart above.' : null)
              }
            />
          </div>
        ))}
      </div>

      {section.records.map((records) => (
        <details
          key={records.table}
          className="rounded-lg border-2 border-border bg-surface p-4 sm:p-6"
        >
          <summary className="cursor-pointer text-base font-semibold text-ink-strong">
            {records.title} · {records.total.toLocaleString('en-GB')}
          </summary>
          <div className="mt-4">
            <RecordsTable table={records.table} title={records.title} params={params} />
          </div>
        </details>
      ))}

      <details className="rounded-md border border-border px-4 py-3">
        <summary className="cursor-pointer text-sm font-semibold text-ink">
          How these figures are calculated
        </summary>
        <ul className="mt-3 flex flex-col gap-2 text-sm text-ink">
          {section.notes.map((note) => (
            <li key={note}>{note}</li>
          ))}
          {meta.definitions.map((d) => (
            <li key={d.term}>
              <strong className="font-semibold">{d.term}.</strong> {d.text}
            </li>
          ))}
          <li className="text-muted">{meta.as_of_label}</li>
        </ul>
      </details>
    </div>
  )
}
