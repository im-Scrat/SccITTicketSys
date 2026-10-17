import { Alert } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { DashboardHeader } from '../components/DashboardHeader'
import { LiveStatus } from '../components/LiveStatus'
import { useAdminDashboardChannel } from '../hooks/useAdminDashboardChannel'
import { useDashboard } from '../hooks/queries'

/**
 * Phase 2.9 — the administrator's dashboard.
 *
 * The widget panels (service desk, technicians, maintenance, assets, estate,
 * people, activity and the charts) were removed at the owner's request; only
 * the header, its live-channel status and the load-error alert remain.
 */
export default function AdminDashboard() {
  const { user } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()
  const { live } = useAdminDashboardChannel()

  return (
    <div className="flex flex-col gap-8">
      <DashboardHeader
        title={user?.first_name ? `Welcome, ${user.first_name}` : 'Dashboard'}
        subtitle="Operations across the whole organization."
        generatedAt={data?.generated_at}
        refreshing={isFetching && !isLoading}
        onRefresh={() => void refetch()}
      >
        <LiveStatus live={live} />
      </DashboardHeader>

      {isError && (
        <Alert tone="error">
          We couldn’t load your dashboard. Please retry — the rest of the console still works.
        </Alert>
      )}
    </div>
  )
}
