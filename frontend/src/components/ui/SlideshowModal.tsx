import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { KeyboardEvent, ReactNode } from 'react'
import { cn } from '@/lib/cn'
import { Button } from './Button'
import { LiveRegion } from './LiveRegion'
import { Modal } from './Modal'

export interface SlideshowSlide {
  id: string
  /** Names the slide for the dots and the announcement ("Room details"). */
  title: string
}

interface SlideshowModalProps {
  open: boolean
  /** Escape, × and the backdrop; closing is never an acknowledgement. */
  onClose: () => void
  /** The dialog heading, e.g. the import outcome. */
  title: ReactNode
  description?: ReactNode
  /** Extra header controls (a "Details" disclosure), placed before the counter. */
  headerExtra?: ReactNode
  slides: SlideshowSlide[]
  index: number
  onIndexChange: (index: number) => void
  /** Explanation of the current slide — beside the stage on wide screens. */
  caption: ReactNode
  /** The current slide's main content (the preview). */
  children: ReactNode
  /** The surface's single primary action, e.g. "I've reviewed – Close". */
  primaryAction: ReactNode
  /** Names the carousel ("Pages updated by this import"). */
  label: string
}

/** Above this many slides the dots stop being a usable target row. */
const MAX_DOTS = 7

/**
 * A near-full-screen dialog stepping through a fixed set of slides, each a
 * caption beside a large stage.
 *
 * Deliberately calm: no auto-advance, no wrap-around from the last slide to
 * the first, a 150 ms fade that reduced motion makes instant. Arrow keys move
 * between slides only while focus is on the slide controls, so they never
 * hijack a scrolling preview or a select in the caption. Each change is
 * announced ("2 of 5: Room details").
 *
 * Layout: at ≥lg the caption column (about 21rem) sits beside the stage, so
 * the height goes to the preview; below that, caption and stage share one
 * scroll container and the controls stay pinned in the footer.
 */
export function SlideshowModal({
  open,
  onClose,
  title,
  description,
  headerExtra,
  slides,
  index,
  onIndexChange,
  caption,
  children,
  primaryAction,
  label,
}: SlideshowModalProps) {
  const count = slides.length
  const current = Math.min(Math.max(index, 0), Math.max(count - 1, 0))
  const slide = slides[current]
  const atStart = current === 0
  const atEnd = current >= count - 1

  const go = (next: number) => {
    if (next >= 0 && next < count && next !== current) onIndexChange(next)
  }

  const onControlsKey = (event: KeyboardEvent<HTMLDivElement>) => {
    const moves: Record<string, number> = {
      ArrowLeft: current - 1,
      ArrowRight: current + 1,
      Home: 0,
      End: count - 1,
    }
    if (!(event.key in moves)) return
    event.preventDefault()
    go(moves[event.key])
  }

  const position = `${current + 1} of ${count}`
  // aria-disabled rather than disabled: a button that disables itself under
  // the user's focus (Previous on reaching the first slide) would drop focus
  // to <body> and lose their place.
  const stepButton =
    'aria-disabled:cursor-not-allowed aria-disabled:text-faint aria-disabled:shadow-none'

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      size="full"
      bodyLayout="flush"
      closeLabel="Close the review"
      headerExtra={
        <>
          {headerExtra}
          {count > 1 && (
            <span className="hidden text-sm tabular-nums text-muted sm:inline">{position}</span>
          )}
        </>
      }
    >
      <div
        role="region"
        aria-roledescription="carousel"
        aria-label={label}
        className="flex min-h-0 flex-1 flex-col"
      >
        {slide && (
          <div
            key={slide.id}
            role="group"
            aria-roledescription="slide"
            aria-label={`${position}: ${slide.title}`}
            className="min-h-0 flex-1 overflow-y-auto animate-fade-in lg:grid lg:grid-cols-[21rem_minmax(0,1fr)] lg:overflow-hidden"
          >
            <div className="border-b border-border px-5 py-4 lg:overflow-y-auto lg:border-b-0 lg:border-r">
              {caption}
            </div>
            <div className="min-w-0 bg-surface-sunken p-3 sm:p-4 lg:overflow-auto">{children}</div>
          </div>
        )}

        <div className="flex shrink-0 flex-col-reverse gap-3 border-t border-border bg-surface px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
          {count > 1 ? (
            <div
              role="group"
              aria-label="Slide controls"
              onKeyDown={onControlsKey}
              className="flex items-center justify-between gap-2 sm:justify-start"
            >
              <Button
                variant="secondary"
                size="sm"
                aria-disabled={atStart || undefined}
                onClick={() => go(current - 1)}
                className={stepButton}
                leftIcon={<ChevronLeft size={18} aria-hidden="true" />}
              >
                Previous
              </Button>

              <span className="text-sm tabular-nums text-muted sm:hidden">{position}</span>

              {count <= MAX_DOTS && (
                <ul className="hidden items-center gap-1 sm:flex">
                  {slides.map((item, dot) => (
                    <li key={item.id}>
                      <button
                        type="button"
                        onClick={() => go(dot)}
                        aria-label={`Slide ${dot + 1}: ${item.title}`}
                        aria-current={dot === current ? 'true' : undefined}
                        className="flex size-8 items-center justify-center rounded-full"
                      >
                        <span
                          aria-hidden="true"
                          className={cn(
                            'rounded-full transition-colors duration-150',
                            dot === current
                              ? 'h-2.5 w-6 bg-ink'
                              : 'size-2.5 border-2 border-control-border bg-surface',
                          )}
                        />
                      </button>
                    </li>
                  ))}
                </ul>
              )}

              <Button
                variant="secondary"
                size="sm"
                aria-disabled={atEnd || undefined}
                onClick={() => go(current + 1)}
                className={stepButton}
                rightIcon={<ChevronRight size={18} aria-hidden="true" />}
              >
                Next
              </Button>
            </div>
          ) : (
            <span />
          )}

          <div className="flex [&>*]:w-full sm:[&>*]:w-auto">{primaryAction}</div>
        </div>

        <LiveRegion message={slide && count > 1 ? `${position}: ${slide.title}` : ''} />
      </div>
    </Modal>
  )
}
