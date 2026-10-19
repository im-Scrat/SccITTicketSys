import { lazy } from 'react'
import { Route, Routes } from 'react-router-dom'
import { RequireReports } from '@/features/analytics/guards/RequireReports'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'
import { RequireFloorPlan } from '@/features/floor-plan/guards/RequireFloorPlan'

// The same page modules the app routes to (the chunks are shared).
const AssetsPage = lazy(() => import('@/features/assets/pages/AssetsPage'))
const AssetDetailPage = lazy(() => import('@/features/assets/pages/AssetDetailPage'))
const PcUnitDetailPage = lazy(() => import('@/features/assets/pages/PcUnitDetailPage'))
const AssetDashboardPage = lazy(() => import('@/features/assets/pages/AssetDashboardPage'))
const MyAssetsPage = lazy(() => import('@/features/assets/pages/MyAssetsPage'))
const LocationsPage = lazy(() => import('@/features/locations/pages/LocationsPage'))
const RoomDetailPage = lazy(() => import('@/features/locations/pages/RoomDetailPage'))
const FloorPlanRoomPage = lazy(() => import('@/features/floor-plan/pages/FloorPlanRoomPage'))
const OverallReportsPage = lazy(() => import('@/features/analytics/pages/OverallReportsPage'))

/**
 * The pages the post-import review can preview (Phase 2.10 R7), behind the
 * same guards as in the app — the server only offers a slide the viewer may
 * open, and the preview refuses the rest just as the app would.
 */
export function PreviewRoutes() {
  return (
    <Routes>
      <Route
        path="/app/assets"
        element={
          <RequirePermission permission="assets.view">
            <AssetDashboardPage />
          </RequirePermission>
        }
      />
      <Route
        path="/app/assets/list"
        element={
          <RequirePermission permission="assets.view">
            <AssetsPage />
          </RequirePermission>
        }
      />
      <Route
        path="/app/assets/pc-units/:id"
        element={
          <RequirePermission permission="assets.view">
            <PcUnitDetailPage />
          </RequirePermission>
        }
      />
      <Route
        path="/app/assets/:id"
        element={
          <RequirePermission permission="assets.view">
            <AssetDetailPage />
          </RequirePermission>
        }
      />
      <Route
        path="/app/my-assets"
        element={
          <RequireRole roles={['technician', 'administrator']}>
            <MyAssetsPage />
          </RequireRole>
        }
      />
      <Route
        path="/app/locations"
        element={
          <RequirePermission permission="locations.view">
            <LocationsPage />
          </RequirePermission>
        }
      />
      <Route
        path="/app/locations/rooms/:id"
        element={
          <RequirePermission permission="locations.view">
            <RoomDetailPage />
          </RequirePermission>
        }
      />
      <Route
        path="/app/floor-plan/rooms/:id"
        element={
          <RequireFloorPlan>
            <FloorPlanRoomPage />
          </RequireFloorPlan>
        }
      />
      <Route
        path="/app/reports"
        element={
          <RequireReports>
            <OverallReportsPage />
          </RequireReports>
        }
      />
      <Route
        path="*"
        element={<p className="p-6 text-sm text-muted">This page cannot be previewed.</p>}
      />
    </Routes>
  )
}
