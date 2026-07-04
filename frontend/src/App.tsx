import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { PageLoader } from '@/components/ui/PageLoader'
import { PublicLayout } from '@/layouts/PublicLayout'

// Route-level code splitting keeps the initial bundle lean (SDD §7.5).
const LandingPage = lazy(() => import('@/pages/LandingPage'))
const SystemStatusPage = lazy(() => import('@/pages/SystemStatusPage'))
const SignInPage = lazy(() => import('@/pages/SignInPage'))
const NotFoundPage = lazy(() => import('@/pages/NotFoundPage'))

function App() {
  return (
    <ErrorBoundary>
      <Suspense fallback={<PageLoader />}>
        <Routes>
          <Route element={<PublicLayout />}>
            <Route path="/" element={<LandingPage />} />
            <Route path="/system-status" element={<SystemStatusPage />} />
            <Route path="*" element={<NotFoundPage />} />
          </Route>
          <Route path="/sign-in" element={<SignInPage />} />
        </Routes>
      </Suspense>
    </ErrorBoundary>
  )
}

export default App
