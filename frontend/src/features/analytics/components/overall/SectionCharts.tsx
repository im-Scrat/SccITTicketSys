import { ChartLegendTable, DonutChart, RankingBars, TimeSeriesChart } from '@/components/charts'
import type { SeriesPoint } from '@/components/charts'
import { WidgetCard } from '@/features/dashboard/components/WidgetCard'
import type { SectionChart } from '../../types'

/** "Mon 6 Oct 2026" → "6 Oct": the axis label; the full label stays in the table. */
function shortLabel(label: string): string {
  return label.replace(/ \d{4}$/, '').replace(/^[A-Z][a-z]{2} (?=\d)/, '')
}

/**
 * One server-described chart drawn with the shared chart kit (Phase 2.10 R4):
 * a line for a series over time (the comparison dashed), a donut for status
 * shares beside its legend table, ranking bars for "most first". Colours come
 * from the theme tokens by key — the server's hex values are for documents.
 * Every chart's figures are also in a table in the same section.
 */
export function SectionChartPanel({ chart }: { chart: SectionChart }) {
  if (chart.kind === 'line') {
    const [current, comparison] = chart.series
    const points: SeriesPoint[] = chart.categories.map((label, i) => ({
      key: chart.keys[i] ?? String(i),
      label,
      shortLabel: shortLabel(label),
      current: current?.values[i] ?? null,
      comparison: comparison ? (comparison.values[i] ?? null) : undefined,
    }))

    return (
      <WidgetCard title={chart.title} description={chart.description} variant="section">
        <TimeSeriesChart
          points={points}
          currentName={current?.name ?? 'This period'}
          comparisonName={comparison?.name}
          unit={chart.value_label.toLowerCase()}
          label={chart.description}
        />
      </WidgetCard>
    )
  }

  const values = chart.series[0]?.values ?? []

  if (chart.kind === 'donut') {
    const total = values.reduce<number>((sum, v) => sum + (v ?? 0), 0)
    const slices = chart.categories.map((label, i) => ({
      key: chart.keys[i] ?? label,
      label,
      count: values[i] ?? 0,
    }))

    return (
      <WidgetCard title={chart.title} description={chart.description} variant="section">
        <div className="flex flex-col items-center gap-6 @container sm:flex-row sm:items-start">
          <DonutChart
            slices={slices}
            size={200}
            centreLabel={chart.value_label.toLowerCase()}
            label={chart.description}
          />
          <div className="w-full min-w-0 flex-1">
            <ChartLegendTable
              rows={slices.map((s) => ({
                ...s,
                pct: total > 0 ? Math.round((s.count / total) * 1000) / 10 : null,
              }))}
              caption={chart.title}
              countLabel={chart.value_label}
            />
          </div>
        </div>
      </WidgetCard>
    )
  }

  return (
    <WidgetCard title={chart.title} description={chart.description} variant="section">
      <RankingBars
        items={chart.categories.map((label, i) => ({
          key: `${label}-${i}`,
          rank: i + 1,
          label,
          count: values[i] ?? 0,
        }))}
        caption={chart.title}
        countLabel={chart.value_label}
        nameLabel="Name"
      />
    </WidgetCard>
  )
}
