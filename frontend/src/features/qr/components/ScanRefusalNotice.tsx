import { ShieldAlert } from 'lucide-react'
import { ButtonLink, Surface } from '@/components/ui'
import type { Refusal } from '../lib/refusals'

/**
 * A refusal, rendered as an explanation rather than an error.
 *
 * Deliberately not an `Alert`. An alert is a strip attached to a page that
 * otherwise worked; this *is* the page — the technician scanned a sticker and
 * the answer is no. Giving it the room to say why, and what to do instead,
 * is the difference between someone fetching a replacement label and someone
 * deciding the system is broken.
 *
 * It never names the equipment. The server withheld that on purpose, and a
 * refusal screen that filled it in from a cached panel would hand back exactly
 * what the refusal was protecting.
 */
export function ScanRefusalNotice({ refusal }: { refusal: Refusal }) {
  return (
    <Surface className="p-6 sm:p-8">
      <div className="flex flex-col gap-4">
        <span
          className="flex size-12 items-center justify-center rounded-md bg-warning-subtle text-warning-strong"
          aria-hidden="true"
        >
          <ShieldAlert className="size-6" />
        </span>

        <div className="flex flex-col gap-2">
          <h1 className="text-xl font-semibold text-balance text-ink">{refusal.title}</h1>
          <p className="max-w-[65ch] text-sm leading-relaxed text-pretty text-muted">
            {refusal.body}
          </p>
          {refusal.action && (
            <p className="max-w-[65ch] text-sm leading-relaxed text-pretty text-ink">
              {refusal.action}
            </p>
          )}
        </div>

        <div className="flex flex-wrap gap-3 pt-2">
          <ButtonLink to="/app" variant="secondary">
            Back to my work
          </ButtonLink>
        </div>
      </div>
    </Surface>
  )
}
