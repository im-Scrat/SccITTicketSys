import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import AdminDashboard from './AdminDashboard'
import TeacherDashboard from './TeacherDashboard'
import TechnicianDashboard from './TechnicianDashboard'

/**
 * The landing dashboard (FR-DSH-001) — since Phase 2.9, **one per role**.
 *
 * Teacher: the community feed beside their own reports. Technician: their
 * assigned work and how to record it at the machine. Administrator: live
 * oversight with the queues that need a decision.
 *
 * The role only chooses the *page*. What each panel contains is still decided
 * by the server from the caller's role and permissions (DashboardService), so
 * no privileged figure is ever filtered client-side.
 */
export default function DashboardPage() {
  useDocumentMeta({ title: 'Dashboard' })
  const { user } = useAuth()

  switch (user?.role.slug) {
    case 'administrator':
      return <AdminDashboard />
    case 'technician':
      return <TechnicianDashboard />
    default:
      return <TeacherDashboard />
  }
}
