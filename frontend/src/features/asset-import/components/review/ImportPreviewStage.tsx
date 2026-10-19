import { Eye } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { usePreviewStore } from '@/stores/usePreviewStore'

const HIGHLIGHT = 'import-value'

type HighlightRegistryLike = {
  set: (name: string, value: unknown) => void
  delete: (name: string) => void
}

/** The CSS Custom Highlight API, where the browser has it. */
function highlightApi(): {
  registry: HighlightRegistryLike
  make: (ranges: Range[]) => unknown
} | null {
  const registry = (CSS as unknown as { highlights?: HighlightRegistryLike }).highlights
  const Ctor = (globalThis as unknown as { Highlight?: new (...ranges: Range[]) => unknown })
    .Highlight
  return registry && Ctor ? { registry, make: (ranges) => new Ctor(...ranges) } : null
}

/**
 * The review's preview of one real page (Phase 2.10 R7, plan §3.5).
 *
 * The page renders natively at the stage's width — no scaling, so its text
 * stays at the 16px floor — through `PreviewPortalRoot`, into the element this
 * stage owns. The page is `inert`: it can be scrolled and read, never clicked,
 * focused or changed. A click on it explains how to open the page itself.
 *
 * Values from the file are marked with the CSS Custom Highlight API: text
 * ranges are registered, nothing in the page's markup changes, and the page
 * is free to re-render underneath. A wash plus an underline, so the mark is a
 * shape as well as a colour; where the browser lacks the API there is simply
 * no mark, and the caption says what came from the file.
 */
export function ImportPreviewStage({ path, values }: { path: string; values: string[] }) {
  const target = useRef<HTMLDivElement>(null)
  const show = usePreviewStore((state) => state.show)
  const clear = usePreviewStore((state) => state.clear)
  const [hint, setHint] = useState(false)

  useEffect(() => {
    if (target.current) show(target.current, path)
    return () => clear()
  }, [path, show, clear])

  useEffect(() => {
    const api = highlightApi()
    const root = target.current
    if (!api || !root || values.length === 0) return

    const needles = [...new Set(values)].sort((a, b) => b.length - a.length)
    let frame = 0
    const apply = () => {
      const ranges: Range[] = []
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
      for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const text = node.nodeValue ?? ''
        if (text.trim().length < 3) continue
        const taken: Array<[number, number]> = []
        for (const needle of needles) {
          let from = text.indexOf(needle)
          while (from !== -1) {
            const to = from + needle.length
            if (!taken.some(([a, b]) => from < b && to > a)) {
              const range = document.createRange()
              range.setStart(node, from)
              range.setEnd(node, to)
              ranges.push(range)
              taken.push([from, to])
            }
            from = text.indexOf(needle, to)
          }
        }
      }
      api.registry.set(HIGHLIGHT, api.make(ranges))
    }
    const observer = new MutationObserver(() => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(apply)
    })
    observer.observe(root, { subtree: true, childList: true, characterData: true })
    apply()

    return () => {
      observer.disconnect()
      cancelAnimationFrame(frame)
      api.registry.delete(HIGHLIGHT)
    }
  }, [values, path])

  return (
    <div className="flex min-h-full flex-col overflow-hidden rounded-md border border-border bg-bg">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border bg-surface px-3 py-2 text-xs text-muted">
        <span className="inline-flex items-center gap-1.5 font-semibold text-ink">
          <Eye size={16} aria-hidden="true" />
          Preview · read-only · live data
        </span>
        <span className="min-w-0 break-all font-mono">{path}</span>
        <span className="ml-auto inline-flex items-center gap-1.5">
          <span aria-hidden="true" className="import-mark-key">
            Abc
          </span>
          Marked: values from your file
        </span>
      </div>
      {/* The page itself is inert; a click lands here and explains. */}
      <div className="relative flex-1 p-4" onClick={() => setHint(true)}>
        {hint && (
          <p
            role="status"
            className="sticky top-0 z-10 mb-3 rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm"
          >
            This preview is read-only. Use “View this page” to open it.
          </p>
        )}
        <div ref={target} inert className="min-w-0" />
      </div>
    </div>
  )
}
