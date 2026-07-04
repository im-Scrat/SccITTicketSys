import { Suspense } from 'react'
import { Outlet } from 'react-router-dom'
import { Navbar } from '@/components/marketing/Navbar'
import { Footer } from '@/components/marketing/Footer'
import { PageLoader } from '@/components/ui/PageLoader'

/**
 * Shell for every public (unauthenticated) route: a skip link, the sticky
 * navbar, the routed page in <main>, and the footer. The inner Suspense keeps
 * the shell in place while a lazy page chunk loads. The authenticated app will
 * live under its own layout in a later phase.
 */
export function PublicLayout() {
  return (
    <div className="flex min-h-dvh flex-col bg-bg">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[1500] focus:rounded-sm focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-on-primary"
      >
        Skip to content
      </a>
      <Navbar />
      <main id="main" className="flex-1">
        <Suspense fallback={<PageLoader />}>
          <Outlet />
        </Suspense>
      </main>
      <Footer />
    </div>
  )
}
