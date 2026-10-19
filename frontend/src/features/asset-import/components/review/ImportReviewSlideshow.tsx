import { ExternalLink } from 'lucide-react'
import { useEffect } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Badge, Button, Select, Skeleton, SlideshowModal } from '@/components/ui'
import { cn } from '@/lib/cn'
import { useAcknowledgeReview } from '../../hooks/mutations'
import { useImportReview } from '../../hooks/queries'
import { plural } from '../../lib/steps'
import type { AssetImport, ReviewSlide } from '../../types'
import { ImportPreviewStage } from './ImportPreviewStage'

/** The route the preview shows for a slide, instance and view. */
function slidePath(slide: ReviewSlide, instanceId: string | null, view: string | null): string {
  const instance = slide.instances.find((i) => i.id === instanceId) ?? slide.instances[0]
  const base = instance?.path ?? slide.path
  return view && view !== 'general' ? `${base}${base.includes('?') ? '&' : '?'}tab=${view}` : base
}

/**
 * The post-import review (Phase 2.10 R7, SRS FR-AST-017, plan §3.5): every
 * page the import's records now appear on, previewed live and read-only.
 *
 * The URL carries the place (`?review=1&slide=&instance=&view=`, replaced as
 * you move), so Back and refresh land on the same slide. Escape, × and the
 * backdrop close without acknowledging — the import is complete either way;
 * only "I've reviewed" writes, and it writes only the receipt.
 */
export function ImportReviewSlideshow({ item }: { item: AssetImport }) {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const open = searchParams.get('review') === '1'
  const review = useImportReview(item.id, open)
  const acknowledge = useAcknowledgeReview(item.id)

  const slides = review.data?.slides ?? []
  const index = Math.max(
    slides.findIndex((s) => s.kind === searchParams.get('slide')),
    0,
  )
  const slide = slides[index]
  const instanceId = searchParams.get('instance')
  const view = searchParams.get('view')
  const instance = slide?.instances.find((i) => i.id === instanceId) ?? slide?.instances[0]

  const patch = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams)
    for (const [key, value] of Object.entries(changes)) {
      if (value === null) next.delete(key)
      else next.set(key, value)
    }
    setSearchParams(next, { replace: true })
  }

  // Name the first slide in the URL once the review is known.
  useEffect(() => {
    if (open && slide && !searchParams.get('slide')) patch({ slide: slide.kind })
    // patch is recreated each render; the URL is what matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, slide?.kind])

  const close = () => patch({ review: null, slide: null, instance: null, view: null })
  const result = review.data?.import.result ?? item.result
  const coversAll = review.data?.receipt.covers_all ?? false

  const caption = slide && (
    <div className="flex flex-col gap-4">
      <div>
        <p className="text-sm text-muted">{slide.page_label}</p>
        <h3 className="text-lg font-semibold text-ink-strong">{slide.title}</h3>
        <p className="mt-2 text-sm text-ink">{slide.explanation}</p>
      </div>

      {slide.views.length > 1 && (
        <div role="group" aria-label="View" className="flex flex-wrap gap-2">
          {slide.views.map((v) => {
            const active = (view ?? 'general') === v.key
            return (
              <button
                key={v.key}
                type="button"
                aria-pressed={active}
                onClick={() => patch({ view: v.key === 'general' ? null : v.key })}
                className={cn(
                  'h-12 rounded-md border-2 px-3 text-sm font-semibold',
                  active
                    ? 'border-primary bg-primary-subtle text-ink-strong'
                    : 'border-control-border bg-surface text-ink',
                )}
              >
                {v.label}
              </button>
            )
          })}
        </div>
      )}

      {slide.instances.length > 1 && (
        <label className="flex flex-col gap-2">
          <span className="text-sm font-semibold text-ink">Which one</span>
          <Select value={instance?.id ?? ''} onChange={(e) => patch({ instance: e.target.value })}>
            {slide.instances.map((i) => (
              <option key={i.id} value={i.id}>
                {i.label}
              </option>
            ))}
          </Select>
        </label>
      )}
      {instance && (
        <p className="text-sm text-muted">
          {instance.hint}
          {instance.edited ? ' · edited since the import' : ''}
          {instance.archived ? ' · archived since the import' : ''}
        </p>
      )}
      {slide.instance_total > slide.instances.length && (
        <p className="text-sm text-muted">
          The first {slide.instances.length} of {slide.instance_total}.{' '}
          <Link
            to={`/app/assets/list?import=${item.id}`}
            className="font-semibold text-primary-strong hover:underline"
          >
            Open the filtered directory
          </Link>
        </p>
      )}

      <Button
        variant="secondary"
        rightIcon={<ExternalLink size={18} aria-hidden="true" />}
        onClick={() =>
          void navigate(slidePath(slide, instanceId, view), {
            state: { importReturn: { id: item.id, file: item.file.name, slide: slide.kind } },
          })
        }
      >
        View this page
      </Button>

      <details className="text-sm text-muted">
        <summary className="cursor-pointer font-semibold text-ink">More about this page</summary>
        <p className="mt-2">
          Highlighted values came from your file; the page itself does not show these marks.
        </p>
      </details>
    </div>
  )

  return (
    <SlideshowModal
      open={open}
      onClose={close}
      label="Pages updated by this import"
      title={result?.headline ?? 'Import review'}
      description={
        result
          ? `${result.detail} Only saved records appear below; skipped duplicates did not change the existing records.`
          : undefined
      }
      headerExtra={
        review.data && (
          <details className="relative text-sm">
            <summary className="cursor-pointer font-semibold text-ink">Details</summary>
            <div className="absolute right-0 z-10 mt-2 flex w-80 flex-col gap-3 rounded-md border border-border bg-surface p-3 shadow-sm">
              {result && (
                <div className="flex flex-wrap gap-2">
                  {result.created_pc_units > 0 && (
                    <Badge>{plural(result.created_pc_units, 'PC unit')}</Badge>
                  )}
                  {result.created_assets > 0 && (
                    <Badge>{plural(result.created_assets, 'asset')}</Badge>
                  )}
                  {result.skipped_duplicates > 0 && (
                    <Badge tone="info">
                      {plural(result.skipped_duplicates, 'duplicate')} skipped
                    </Badge>
                  )}
                  {result.failed + result.not_attempted > 0 && (
                    <Badge tone="warning">
                      {result.failed + result.not_attempted} not saved: retry from the summary
                    </Badge>
                  )}
                  {result.catalog_created > 0 && (
                    <Badge>{plural(result.catalog_created, 'catalog model')} added</Badge>
                  )}
                </div>
              )}
              <div>
                <p className="font-semibold text-ink">About these previews</p>
                <ul className="mt-1 flex list-disc flex-col gap-1 pl-5 text-muted">
                  {review.data.exclusions.map((e) => (
                    <li key={e.kind}>
                      {e.label}: {e.reason}
                    </li>
                  ))}
                  {review.data.selectable_elsewhere.map((s) => (
                    <li key={s}>{s}</li>
                  ))}
                </ul>
              </div>
            </div>
          </details>
        )
      }
      slides={slides.map((s) => ({ id: s.kind, title: s.title }))}
      index={index}
      onIndexChange={(next) =>
        patch({ slide: slides[next]?.kind ?? null, instance: null, view: null })
      }
      caption={caption ?? <Skeleton className="h-40 w-full" />}
      primaryAction={
        <Button
          disabled={acknowledge.isPending || !review.data}
          onClick={() =>
            coversAll ? close() : acknowledge.mutate(undefined, { onSuccess: close })
          }
        >
          {coversAll ? 'Close' : 'I’ve reviewed – Close'}
        </Button>
      }
    >
      {review.isError ? (
        <Alert tone="error">The review could not be loaded.</Alert>
      ) : slide ? (
        <ImportPreviewStage
          path={slidePath(slide, instanceId, view)}
          values={review.data?.highlight_values ?? []}
        />
      ) : review.isLoading ? (
        <Skeleton className="h-96 w-full" />
      ) : (
        <Alert tone="info">No page shows records from this import.</Alert>
      )}
    </SlideshowModal>
  )
}
