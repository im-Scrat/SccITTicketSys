import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { PageLoader } from '@/components/ui/PageLoader'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { GuestRoute } from '@/features/auth/guards/GuestRoute'
import { ProtectedRoute } from '@/features/auth/guards/ProtectedRoute'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'
import { RequireFloorPlan } from '@/features/floor-plan/guards/RequireFloorPlan'
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

// Notification centre (Phase 2.7 / WP-2.7b).
const NotificationsPage = lazy(() => import('@/features/notifications/pages/NotificationsPage'))

// Announcements (Phase 2.7 / WP-2.7c).
const AnnouncementsPage = lazy(() => import('@/features/announcements/pages/AnnouncementsPage'))
const AnnouncementManagementPage = lazy(
  () => import('@/features/announcements/pages/AnnouncementManagementPage'),
)
const AnnouncementDetailPage = lazy(
  () => import('@/features/announcements/pages/AnnouncementDetailPage'),
)

const RegistrationsPage = lazy(() => import('@/features/users/pages/RegistrationsPage'))
const UsersDashboardPage = lazy(() => import('@/features/users/pages/UsersDashboardPage'))
const UserDetailPage = lazy(() => import('@/features/users/pages/UserDetailPage'))

// Role dashboards (Analytics) + Location Management feature slices.
const DashboardPage = lazy(() => import('@/features/dashboard/pages/DashboardPage'))
const LocationsPage = lazy(() => import('@/features/locations/pages/LocationsPage'))
const BuildingDetailPage = lazy(() => import('@/features/locations/pages/BuildingDetailPage'))
const RoomDetailPage = lazy(() => import('@/features/locations/pages/RoomDetailPage'))

// Interactive Floor Plan (Phase 2.8 / WP-C) — read-only map, Administrator-only.
const FloorPlanPage = lazy(() => import('@/features/floor-plan/pages/FloorPlanPage'))
const FloorPlanRoomPage = lazy(() => import('@/features/floor-plan/pages/FloorPlanRoomPage'))

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

// Maintenance (Phase 2.7).
const MaintenanceQueuePage = lazy(() => import('@/features/maintenance/pages/MaintenanceQueuePage'))
const CreateMaintenancePage = lazy(
  () => import('@/features/maintenance/pages/CreateMaintenancePage'),
)
const ScheduledMaintenancePage = lazy(
  () => import('@/features/maintenance/pages/ScheduledMaintenancePage'),
)
const MaintenanceHistoryPage = lazy(
  () => import('@/features/maintenance/pages/MaintenanceHistoryPage'),
)
const MaintenanceManagementPage = lazy(
  () => import('@/features/maintenance/pages/MaintenanceManagementPage'),
)
const MaintenanceDetailPage = lazy(
  () => import('@/features/maintenance/pages/MaintenanceDetailPage'),
)

// Scanned technician workflow (WP-2.6b).
const ScanLandingPage = lazy(() => import('@/features/qr/pages/ScanLandingPage'))
const ScanPanelPage = lazy(() => import('@/features/qr/pages/ScanPanelPage'))

// Work support requests (WP-2.6b Stage E).
const MySubmissionsPage = lazy(() => import('@/features/work-support/pages/MySubmissionsPage'))
const SupportRequestInboxPage = lazy(
  () => import('@/features/work-support/pages/SupportRequestInboxPage'),
)

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

            {/*
              The scanned label's landing route (FR-QR-005/009/010).

              Public, and outside every layout, deliberately. A printed sticker
              is scanned by whoever is holding the phone — often before signing
              in, sometimes by someone with no account at all — and FR-QR-010
              requires that attempt to be *recorded* and answered without
              disclosing anything. A route guard here would redirect before the
              scan reached the server, losing exactly the attempts worth having
              in the log. The page itself shows nothing about the equipment; the
              server decides where the visitor goes next.
            */}
            <Route path="/qr/:code" element={<ScanLandingPage />} />

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
                {/* Notifications are addressed to a person, not granted by a
                    permission — every authenticated user has them, and no
                    `notifications.*` permission exists. So this route is
                    ungated exactly like /app/account, and ownership is enforced
                    server-side on every endpoint behind it (FR-NOT-001). */}
                <Route path="notifications" element={<NotificationsPage />} />
                {/* The announcement reader is ungated for the same reason the
                    notification centre is: an announcement is addressed to a
                    role, and the audience scope is applied server-side, so
                    there is no permission to check here (FR-NOT-011). */}
                <Route path="announcements" element={<AnnouncementsPage />} />
                <Route
                  path="announcements/manage"
                  element={
                    <RequirePermission permission="system.announcements.manage">
                      <AnnouncementManagementPage />
                    </RequirePermission>
                  }
                />
                {/* Declared after `manage` so the literal segment is never read
                    as an id. This is the destination every announcement
                    notification carries (decision D3). */}
                <Route path="announcements/:id" element={<AnnouncementDetailPage />} />
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
                {/* Maintenance is the second module whose *permission* does
                    not separate the roles: `maintenance.*` is seeded to
                    Administrators and Technicians alike, and which records each
                    may reach is decided by MaintenanceVisibility on every query
                    and on every direct uuid (SDD DD-55). So these routes are
                    gated by `maintenance.view` only as a floor. A Teacher holds
                    no maintenance permission at all, so every one of them is the
                    Forbidden surface for them — and the API refuses the calls
                    behind it regardless. */}
                {/*
                  The scan-scoped panel and its proof-of-work form (FR-QR-012,
                  FR-MNT-009).

                  Gated on `maintenance.view` only as a floor — the same floor
                  the API checks, and for the same reason it is not the control.
                  No permission can express "this machine"; `ScannedPcAccess`
                  decides that per unit on every request, and this route being
                  reachable proves nothing about whether the panel will open.
                */}
                <Route
                  path="qr/:code"
                  element={
                    <RequirePermission permission="maintenance.view">
                      <ScanPanelPage />
                    </RequirePermission>
                  }
                />
                {/*
                  Work support requests (FR-WSR-009/010).

                  Both surfaces sit on the same `maintenance.view` floor and are
                  separated by role, because `maintenance.view` is a floor every
                  technician clears and no permission distinguishes oversight
                  from fieldwork (Client decision OD-4 — no `wsr.*` was
                  invented).

                  The tracking page is **technician-only** (Client decision,
                  2026-08-30): "My submissions" is the technician's record of
                  what they submitted, and the administrator's counterpart is
                  the inbox below — what others sent them. An administrator gets
                  Forbidden here rather than an empty personal history.

                  UX gating only, as everywhere else: `GET /api/technician/
                  submissions` takes no identifier and reads the session, so it
                  cannot express "someone else's submissions" whatever the route
                  allows.
                */}
                <Route
                  path="work-support"
                  element={
                    <RequirePermission permission="maintenance.view">
                      <RequireRole roles={['technician']}>
                        <MySubmissionsPage />
                      </RequireRole>
                    </RequirePermission>
                  }
                />
                <Route
                  path="work-support/manage"
                  element={
                    <RequireRole roles={['administrator']}>
                      <SupportRequestInboxPage />
                    </RequireRole>
                  }
                />
                <Route
                  path="maintenance"
                  element={
                    <RequirePermission permission="maintenance.view">
                      <MaintenanceQueuePage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="maintenance/new"
                  element={
                    <RequirePermission permission="maintenance.create">
                      <CreateMaintenancePage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="maintenance/scheduled"
                  element={
                    <RequirePermission permission="maintenance.view">
                      <ScheduledMaintenancePage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="maintenance/history"
                  element={
                    <RequirePermission permission="maintenance.view">
                      <MaintenanceHistoryPage />
                    </RequirePermission>
                  }
                />
                <Route
                  path="maintenance/manage"
                  element={
                    <RequireRole roles={['administrator']}>
                      <MaintenanceManagementPage />
                    </RequireRole>
                  }
                />
                {/* Declared last so every literal segment above wins over the
                    uuid wildcard. */}
                <Route
                  path="maintenance/:id"
                  element={
                    <RequirePermission permission="maintenance.view">
                      <MaintenanceDetailPage />
                    </RequirePermission>
                  }
                />
                {/* The floor plan is Administrator-only: the guard requires the
                    role as well as `floorplan.view`, because a permission alone
                    can be granted to an individual Technician or Teacher. The
                    API refuses them regardless (WP-B `FloorPlanAccess`). */}
                <Route
                  path="floor-plan"
                  element={
                    <RequireFloorPlan>
                      <FloorPlanPage />
                    </RequireFloorPlan>
                  }
                />
                <Route
                  path="floor-plan/rooms/:id"
                  element={
                    <RequireFloorPlan>
                      <FloorPlanRoomPage />
                    </RequireFloorPlan>
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
