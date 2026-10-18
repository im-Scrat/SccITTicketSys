import { Suspense } from 'react'
import { createPortal } from 'react-dom'
import { MemoryRouter } from 'react-router-dom'
import { Skeleton } from '@/components/ui'
import { PreviewModeContext } from '@/contexts/PreviewModeContext'
import { usePreviewStore } from '@/stores/usePreviewStore'
import { PreviewRoutes } from './previewRoutes'

/**
 * Renders the post-import review's preview (Phase 2.10 R7): a real page, on
 * its own `MemoryRouter`, portalled into the review's stage.
 *
 * Mounted in `main.tsx` *after* the app's `BrowserRouter`, not inside it — a
 * router cannot sit inside another — but inside the query client and theme,
 * so the preview reads the same cache and session as the app. Its location is
 * its own: moving around inside it never changes the app's URL.
 */
export function PreviewPortalRoot() {
  const target = usePreviewStore((state) => state.target)
  const path = usePreviewStore((state) => state.path)

  if (target === null || path === null) return null

  return createPortal(
    <PreviewModeContext.Provider value={true}>
      <MemoryRouter key={path} initialEntries={[path]}>
        <Suspense fallback={<Skeleton className="h-96 w-full" />}>
          <PreviewRoutes />
        </Suspense>
      </MemoryRouter>
    </PreviewModeContext.Provider>,
    target,
  )
}
