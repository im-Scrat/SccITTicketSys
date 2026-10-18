import { lazy, Suspense } from 'react'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { AdminDashboardSkeleton } from '../components/AdminDashboardSkeleton'
import TeacherDashboard from './TeacherDashboard'
import TechnicianDashboard from './TechnicianDashboard'

/*
 * Phase 2.10: the administrator's page draws charts, and the chart library is
 * the heaviest thing in the app. Loaded on demand, so teachers and technicians
 * — and the older machines many of them use — never download it.
 */
const AdminDashboard = lazy(() => import('./AdminDashboard'))

/**
 * The landing dashboard (FR-DSH-001) — since Phase 2.9, **one per role**.
 *
 * Teacher: the community feed beside their own reports. Technician: their
 * assigned work and how to record it at the machine. Administrator: the ticket
 * overview (Phase 2.10) with its filters and charts.
 *
 * The role only chooses the *page*. What each page contains is still decided
 * by the server from the caller's role and permissions, so no privileged
 * figure is ever filtered client-side.
 */
export default function DashboardPage() {
  useDocumentMeta({ title: 'Dashboard' })
  const { user } = useAuth()

  switch (user?.role.slug) {
    case 'administrator':
      return (
        <Suspense fallback={<AdminDashboardSkeleton />}>
          <AdminDashboard />
        </Suspense>
      )
    case 'technician':
      return <TechnicianDashboard />
    default:
      return <TeacherDashboard />
  }
}
