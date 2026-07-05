import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { PageLoader } from '@/components/ui/PageLoader'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { GuestRoute } from '@/features/auth/guards/GuestRoute'
import { ProtectedRoute } from '@/features/auth/guards/ProtectedRoute'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { PublicLayout } from '@/layouts/PublicLayout'

// Route-level code splitting keeps the initial bundle lean (SDD §7.5).
const LandingPage = lazy(() => import('@/pages/LandingPage'))
const SystemStatusPage = lazy(() => import('@/pages/SystemStatusPage'))
const NotFoundPage = lazy(() => import('@/pages/NotFoundPage'))

// Auth (Identity) feature slice.
const AppLayout = lazy(() => import('@/layouts/AppLayout').then((m) => ({ default: m.AppLayout })))
const SignInPage = lazy(() => import('@/features/auth/pages/SignInPage'))
const RegisterPage = lazy(() => import('@/features/auth/pages/RegisterPage'))
const RegistrationSubmittedPage = lazy(
  () => import('@/features/auth/pages/RegistrationSubmittedPage'),
)
const PendingApprovalPage = lazy(() => import('@/features/auth/pages/PendingApprovalPage'))
const ForgotPasswordPage = lazy(() => import('@/features/auth/pages/ForgotPasswordPage'))
const ResetPasswordPage = lazy(() => import('@/features/auth/pages/ResetPasswordPage'))
const WorkspaceHomePage = lazy(() => import('@/features/auth/pages/WorkspaceHomePage'))
const RegistrationsPage = lazy(() => import('@/features/users/pages/RegistrationsPage'))
const UsersDashboardPage = lazy(() => import('@/features/users/pages/UsersDashboardPage'))
const UserDetailPage = lazy(() => import('@/features/users/pages/UserDetailPage'))

function App() {
  return (
    <ErrorBoundary>
      <AuthProvider>
        <Suspense fallback={<PageLoader />}>
          <Routes>
            {/* Public marketing site (unchanged Phase 2.1 baseline). */}
            <Route element={<PublicLayout />}>
              <Route path="/" element={<LandingPage />} />
              <Route path="/system-status" element={<SystemStatusPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>

            {/* Guest-only auth screens. */}
            <Route element={<GuestRoute />}>
              <Route path="/sign-in" element={<SignInPage />} />
              <Route path="/register" element={<RegisterPage />} />
              <Route path="/forgot-password" element={<ForgotPasswordPage />} />
              <Route path="/reset-password" element={<ResetPasswordPage />} />
            </Route>

            {/* Post-submission acknowledgements (reachable in any auth state). */}
            <Route path="/register/submitted" element={<RegistrationSubmittedPage />} />
            <Route path="/pending-approval" element={<PendingApprovalPage />} />

            {/* Authenticated application. */}
            <Route element={<ProtectedRoute />}>
              <Route path="/app" element={<AppLayout />}>
                <Route index element={<WorkspaceHomePage />} />
                <Route
                  path="users"
                  element={
                    <RequirePermission permission="users.view">
                      <UsersDashboardPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="users/:id"
                  element={
                    <RequirePermission permission="users.view">
                      <UserDetailPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="registrations"
                  element={
                    <RequirePermission permission="users.update">
                      <RegistrationsPage />
                    </RequirePermission>
                  }
                />
              </Route>
            </Route>
          </Routes>
        </Suspense>
      </AuthProvider>
    </ErrorBoundary>
  )
}

export default App
