import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { PageLoader } from '@/components/ui/PageLoader'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { GuestRoute } from '@/features/auth/guards/GuestRoute'
import { ProtectedRoute } from '@/features/auth/guards/ProtectedRoute'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'
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
const AccountPage = lazy(() => import('@/features/auth/pages/WorkspaceHomePage'))
const RegistrationsPage = lazy(() => import('@/features/users/pages/RegistrationsPage'))
const UsersDashboardPage = lazy(() => import('@/features/users/pages/UsersDashboardPage'))
const UserDetailPage = lazy(() => import('@/features/users/pages/UserDetailPage'))

// Role dashboards (Analytics) + Location Management feature slices.
const DashboardPage = lazy(() => import('@/features/dashboard/pages/DashboardPage'))
const LocationsPage = lazy(() => import('@/features/locations/pages/LocationsPage'))
const BuildingDetailPage = lazy(() => import('@/features/locations/pages/BuildingDetailPage'))
const RoomDetailPage = lazy(() => import('@/features/locations/pages/RoomDetailPage'))

// Asset Management (Phase 2.5).
const AssetDashboardPage = lazy(() => import('@/features/assets/pages/AssetDashboardPage'))
const AssetsPage = lazy(() => import('@/features/assets/pages/AssetsPage'))
const AssetDetailPage = lazy(() => import('@/features/assets/pages/AssetDetailPage'))
const PcUnitDetailPage = lazy(() => import('@/features/assets/pages/PcUnitDetailPage'))

// Ticket Management (Phase 2.6).
const TicketFeedPage = lazy(() => import('@/features/tickets/pages/TicketFeedPage'))
const CreateTicketPage = lazy(() => import('@/features/tickets/pages/CreateTicketPage'))
const MyTicketsPage = lazy(() => import('@/features/tickets/pages/MyTicketsPage'))
const TicketDetailPage = lazy(() => import('@/features/tickets/pages/TicketDetailPage'))
const AssignedTicketsPage = lazy(() => import('@/features/tickets/pages/AssignedTicketsPage'))
const AssignmentHistoryPage = lazy(() => import('@/features/tickets/pages/AssignmentHistoryPage'))
const AssignedTicketDetailPage = lazy(
  () => import('@/features/tickets/pages/AssignedTicketDetailPage'),
)
const TicketManagementPage = lazy(() => import('@/features/tickets/pages/TicketManagementPage'))
const TicketAdminDetailPage = lazy(() => import('@/features/tickets/pages/TicketAdminDetailPage'))

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
                {/* The role-aware dashboard is the landing surface (FR-DSH-001);
                    profile and password moved to /app/account. */}
                <Route index element={<DashboardPage />} />
                <Route path="account" element={<AccountPage />} />
                {/* Locations is site administration: `locations.view` is seeded to
                    Administrators only, so a Technician or Teacher who types one of
                    these URLs gets the Forbidden page — and the API refuses the
                    calls behind it regardless (FR-LOC-011). */}
                <Route
                  path="locations"
                  element={
                    <RequirePermission permission="locations.view">
                      <LocationsPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="locations/buildings/:id"
                  element={
                    <RequirePermission permission="locations.view">
                      <BuildingDetailPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="locations/rooms/:id"
                  element={
                    <RequirePermission permission="locations.view">
                      <RoomDetailPage />
                    </RequirePermission>
                  }
                />
                {/* Asset Management is site administration: `assets.view` is
                    seeded to Administrators only, so a Technician or Teacher who
                    types one of these URLs gets the Forbidden page — and the API
                    refuses the calls behind it regardless (SDD DD-38). */}
                <Route
                  path="assets"
                  element={
                    <RequirePermission permission="assets.view">
                      <AssetDashboardPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="assets/list"
                  element={
                    <RequirePermission permission="assets.view">
                      <AssetsPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="assets/pc-units/:id"
                  element={
                    <RequirePermission permission="assets.view">
                      <PcUnitDetailPage />
                    </RequirePermission>
                  }
                />
                {/* Declared after `assets/list` and `assets/pc-units/:id` so the
                    literal segments win over the uuid wildcard. */}
                <Route
                  path="assets/:id"
                  element={
                    <RequirePermission permission="assets.view">
                      <AssetDetailPage />
                    </RequirePermission>
                  }
                />
                {/* Ticket Management is the one module whose *permission* does
                    not separate the roles: `tickets.view` is seeded to all three,
                    and which rows each may reach is decided by TicketVisibility
                    on every query and on every direct uuid (SDD DD-40). So these
                    routes are gated by `tickets.view` only as a floor — the
                    administrator surface additionally checks role, and each page
                    lands on whatever the API is willing to serve that caller. A
                    ticket that is absent from someone's feed is equally absent
                    from `/app/tickets/{uuid}`. */}
                <Route
                  path="tickets"
                  element={
                    <RequirePermission permission="tickets.view">
                      <TicketFeedPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="tickets/new"
                  element={
                    <RequirePermission permission="tickets.create">
                      <CreateTicketPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="tickets/mine"
                  element={
                    <RequirePermission permission="tickets.view">
                      <MyTicketsPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="tickets/assigned"
                  element={
                    <RequirePermission permission="tickets.update">
                      <AssignedTicketsPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="tickets/assigned/:id"
                  element={
                    <RequirePermission permission="tickets.update">
                      <AssignedTicketDetailPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="tickets/history"
                  element={
                    <RequirePermission permission="tickets.update">
                      <AssignmentHistoryPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="tickets/manage"
                  element={
                    <RequireRole roles={['administrator']}>
                      <TicketManagementPage />
                    </RequireRole>
                  }
                />
                <Route
                  path="tickets/manage/:id"
                  element={
                    <RequireRole roles={['administrator']}>
                      <TicketAdminDetailPage />
                    </RequireRole>
                  }
                />
                {/* Declared last so every literal segment above wins over the
                    uuid wildcard. */}
                <Route
                  path="tickets/:id"
                  element={
                    <RequirePermission permission="tickets.view">
                      <TicketDetailPage />
                    </RequirePermission>
                  }
                />
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
