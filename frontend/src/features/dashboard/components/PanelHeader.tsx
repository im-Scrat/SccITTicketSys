import { ArrowRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

interface PanelHeaderProps {
  /** Lets the framed content below name itself after this heading. */
  id?: string
  title: string
  description?: ReactNode
  link?: { to: string; label: string }
  /**
   * A display control for the panel's own content (a chart's "Group by"),
   * on the title row like the link. Never a filter: a control here must not
   * change any other panel on the page.
   */
  actions?: ReactNode
}

/**
 * The heading block above a page-level panel: title, one line saying what it is
 * for, and one link into the full view.
 *
 * It exists so that two columns of panels share **one** definition of "heading".
 * The teacher dashboard's "Reported problems" and "My requests" both render this,
 * so their title size, weight, spacing and link style cannot drift apart — which
 * they had, when each was written by hand.
 *
 * The link rides the **title row**, on the title's baseline, and the description
 * spans the full width beneath. That is what keeps two headers level with each
 * other: their titles, their links and their first description line each share a
 * line, however differently the descriptions then wrap. On a column too narrow
 * for title and link together, the link drops beneath the title.
 */
export function PanelHeader({ id, title, description, link, actions }: PanelHeaderProps) {
  return (
    <div>
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 id={id} className="text-lg font-semibold text-ink-strong">
          {title}
        </h2>
        {actions}
        {link && (
          <Link
            to={link.to}
            className="inline-flex items-center gap-1.5 rounded-sm text-sm font-semibold text-primary-strong hover:underline"
          >
            {link.label}
            <ArrowRight size={16} aria-hidden="true" />
          </Link>
        )}
      </div>
      {description && <p className="mt-1 text-sm text-muted">{description}</p>}
    </div>
  )
}
