import type { DashboardWidget } from '../types'
import { AnnouncementList } from './AnnouncementList'
import { DistributionMeter } from './DistributionMeter'
import { KpiRow } from './KpiRow'
import { PanelHeader } from './PanelHeader'
import { QuickActions } from './QuickActions'
import { WidgetCard, type WidgetVariant } from './WidgetCard'
import { WidgetList } from './WidgetList'

/**
 * One server-built dashboard panel (FR-DSH-001). The server decides what each
 * role receives and what each panel contains; this only renders the shape.
 * Extracted from DashboardPage in Phase 2.9 so the three role dashboards
 * compose the same panels in their own order.
 *
 * `variant` is how a page asks for its panels to be framed like its own
 * content (see {@link WidgetVariant}); the default is the widget card, so a
 * dashboard that says nothing renders exactly what it always did.
 */
export function ServerWidget({
  widget,
  variant = 'card',
}: {
  widget: DashboardWidget
  variant?: WidgetVariant
}) {
  switch (widget.type) {
    case 'kpi':
      if (variant === 'section') {
        return (
          <section aria-labelledby={`${widget.key}-heading`} className="flex flex-col gap-4">
            <PanelHeader
              id={`${widget.key}-heading`}
              title={widget.title}
              description={widget.description}
            />
            <KpiRow items={widget.items} />
          </section>
        )
      }

      return (
        <section aria-labelledby={`${widget.key}-heading`}>
          <div className="mb-2.5 flex items-baseline justify-between gap-3">
            <h2 id={`${widget.key}-heading`} className="text-sm font-semibold text-ink-strong">
              {widget.title}
            </h2>
            {widget.description && <p className="text-xs text-muted">{widget.description}</p>}
          </div>
          <KpiRow items={widget.items} />
        </section>
      )

    case 'distribution':
      return (
        <WidgetCard
          title={widget.title}
          description={widget.description}
          href={widget.href}
          variant={variant}
        >
          <DistributionMeter rows={widget.rows} unit={widget.unit} emptyLabel={widget.empty} />
        </WidgetCard>
      )

    case 'list':
      return (
        <WidgetCard
          title={widget.title}
          description={widget.description}
          href={widget.href}
          linkLabel={widget.linkLabel}
          variant={variant}
        >
          <WidgetList columns={widget.columns} rows={widget.rows} emptyLabel={widget.empty} />
        </WidgetCard>
      )

    case 'actions':
      return (
        <WidgetCard title={widget.title} description={widget.description} variant={variant}>
          <QuickActions items={widget.items} />
        </WidgetCard>
      )

    case 'announcements':
      return (
        <WidgetCard
          title={widget.title}
          description={widget.description}
          href={widget.href}
          linkLabel={widget.linkLabel}
          variant={variant}
        >
          <AnnouncementList rows={widget.rows} />
        </WidgetCard>
      )

    default:
      return null
  }
}
