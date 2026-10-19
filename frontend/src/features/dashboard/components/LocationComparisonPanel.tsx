import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge, Button } from '@/components/ui'
import { RankingBars } from '@/components/charts'
import { drilldownHref } from '@/features/analytics/lib/drilldown'
import type { TicketOverview } from '@/features/analytics/types'
import { WidgetCard } from './WidgetCard'

const SHOWN_FIRST = 10

/**
 * "Where problems were reported" (Phase 2.10): laboratories against offices,
 * then the rooms with the most problems.
 *
 * Counted as **problems** — reported tickets that are neither a duplicate nor
 * cancelled — so a fault reported twice does not double a room's count, and by
 * the room each ticket was *reported* in, archived rooms included and named.
 * "Per room" divides by the rooms of that kind that existed during the period,
 * so a building with more labs does not look worse merely for being larger.
 */
export function LocationComparisonPanel({ overview }: { overview: TicketOverview }) {
  const locations = overview.data.locations
  const [showAll, setShowAll] = useState(false)

  if (!locations) return null

  const ranking = showAll ? locations.ranking : locations.ranking.slice(0, SHOWN_FIRST)

  return (
    <WidgetCard
      variant="section"
      title="Where problems were reported"
      description="Problems are reported tickets that are not duplicates and not cancelled, counted by the room each was reported in."
    >
      {locations.problems_total === 0 ? (
        <p className="text-sm text-muted">No problems were reported in this period.</p>
      ) : (
        <div className="grid grid-cols-1 gap-8 xl:grid-cols-12">
          <section
            aria-labelledby="types-heading"
            className="flex min-w-0 flex-col gap-3 xl:col-span-5"
          >
            <h3 id="types-heading" className="text-base font-semibold text-ink-strong">
              Laboratories, offices and other rooms
            </h3>
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-sm">
                <caption className="sr-only">Problems by type of room</caption>
                <thead>
                  <tr className="border-b border-border text-left text-xs text-muted">
                    <th scope="col" className="py-2 pr-3 font-semibold">
                      Type
                    </th>
                    <th scope="col" className="py-2 pr-3 text-right font-semibold">
                      Problems
                    </th>
                    <th
                      scope="col"
                      className="hidden py-2 pr-3 text-right font-semibold sm:table-cell"
                    >
                      Rooms
                    </th>
                    <th scope="col" className="py-2 text-right font-semibold">
                      Per room
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {locations.by_type.map((row) => {
                    const href = drilldownHref(row.drilldown)
                    return (
                      <tr key={row.type} className="border-b border-border last:border-0">
                        <th scope="row" className="py-2 pr-3 text-left font-normal">
                          {href ? (
                            <Link
                              to={href}
                              className="rounded-sm text-ink underline-offset-2 hover:underline"
                            >
                              {row.label}
                            </Link>
                          ) : (
                            <span className="text-ink">{row.label}</span>
                          )}
                        </th>
                        <td className="py-2 pr-3 text-right text-ink-strong tnum">
                          {row.count.toLocaleString()}
                        </td>
                        <td className="hidden py-2 pr-3 text-right text-muted tnum sm:table-cell">
                          {row.rooms_total === null ? '—' : row.rooms_total.toLocaleString()}
                        </td>
                        <td className="py-2 text-right text-muted tnum">
                          {row.per_room === null ? '—' : row.per_room.toFixed(1)}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </section>

          <section
            aria-labelledby="rooms-heading"
            className="flex min-w-0 flex-col gap-3 xl:col-span-7"
          >
            <h3 id="rooms-heading" className="text-base font-semibold text-ink-strong">
              Rooms with the most problems
            </h3>
            {locations.ranking.length === 0 ? (
              <p className="text-sm text-muted">None of these problems had a room recorded.</p>
            ) : (
              <>
                <RankingBars
                  caption="Rooms ranked by problems"
                  nameLabel="Room"
                  countLabel="Problems"
                  items={ranking.map((row) => ({
                    key: row.room.id,
                    rank: row.rank,
                    label: row.room.name,
                    count: row.count,
                    href: drilldownHref(row.drilldown),
                    detail: (
                      <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span>
                          {[row.room.building?.name, row.room.room_type_label]
                            .filter(Boolean)
                            .join(' · ')}
                        </span>
                        {row.room.archived && <Badge tone="neutral">Archived</Badge>}
                        {!row.room.archived && row.room.inactive && (
                          <Badge tone="neutral">Inactive</Badge>
                        )}
                      </span>
                    ),
                  }))}
                />
                {locations.ranking.length > SHOWN_FIRST && (
                  <Button
                    variant="secondary"
                    size="sm"
                    className="self-start"
                    aria-expanded={showAll}
                    onClick={() => setShowAll((current) => !current)}
                  >
                    {showAll ? 'Show the top 10' : `Show all ${locations.ranking.length} rooms`}
                  </Button>
                )}
                {locations.others && (
                  <p className="text-xs text-muted">
                    {locations.others.count.toLocaleString()} more problems in{' '}
                    {locations.others.rooms.toLocaleString()} other rooms are not listed.
                  </p>
                )}
              </>
            )}
          </section>
        </div>
      )}
    </WidgetCard>
  )
}
