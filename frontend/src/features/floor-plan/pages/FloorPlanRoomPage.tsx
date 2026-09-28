import { isAxiosError } from 'axios'
import { ArrowLeft, LayoutGrid } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { Alert, EmptyState, PageLoader } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import ForbiddenPage from '@/pages/ForbiddenPage'
import { FloorPlanCanvas } from '../components/FloorPlanCanvas'
import { FloorPlanLegend } from '../components/FloorPlanLegend'
import { FloorPlanToolbar } from '../components/FloorPlanToolbar'
import { PcUnitList } from '../components/PcUnitList'
import { useRoomPlan } from '../hooks/queries'

/**
 * One room's floor plan, read-only.
 *
 * **Fails closed on the server's answer.** The route guard has already checked
 * the role and permission, but that is only a courtesy: the backend refuses
 * everyone but an Administrator, and a 403 from it — for any reason, including
 * a stale or tampered client — replaces the page with the Forbidden screen. No
 * plan data is ever rendered from a failed response.
 */
export default function FloorPlanRoomPage() {
  const { id } = useParams<{ id: string }>()
  const { data: plan, isLoading, error } = useRoomPlan(id)

  useDocumentMeta({ title: plan ? `${plan.room.name} floor plan` : 'Floor plan' })

  if (isLoading) return <PageLoader />

  if (isAxiosError(error) && (error.response?.status === 403 || error.response?.status === 401)) {
    return <ForbiddenPage />
  }

  if (isAxiosError(error) && error.response?.status === 404) {
    return <Alert tone="error">That room could not be found. It may have been archived.</Alert>
  }

  if (error || !plan) {
    return <Alert tone="error">We couldn’t load this floor plan.</Alert>
  }

  const where = [plan.room.building?.name, plan.room.floor?.name].filter(Boolean).join(' · ')
  const { layout } = plan

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Link
          to="/app/floor-plan"
          className="inline-flex items-center gap-1.5 rounded-sm text-muted hover:text-ink"
        >
          <ArrowLeft size={15} aria-hidden="true" />
          Floor plan
        </Link>
      </div>

      <header>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          {plan.room.name}
        </h1>
        <p className="mt-1 text-sm text-muted">
          {where || plan.room.code}
          {layout && (
            <>
              {' · '}
              <span className="tnum">
                Layout v{layout.version}, {layout.width} × {layout.height}
              </span>
            </>
          )}
        </p>
      </header>

      {layout === null ? (
        <EmptyState
          icon={<LayoutGrid size={22} />}
          title="No floor plan for this room yet"
          description={
            plan.unplaced_count > 0
              ? `${plan.unplaced_count} ${plan.unplaced_count === 1 ? 'unit is' : 'units are'} in this room, but the room has no active layout to place them on.`
              : 'This room has no active layout and no units.'
          }
        />
      ) : (
        <>
          {plan.unplaced_count > 0 && (
            <Alert tone="info">
              {plan.unplaced_count}{' '}
              {plan.unplaced_count === 1 ? 'unit in this room is' : 'units in this room are'} not
              placed on the plan yet.
            </Alert>
          )}

          <FloorPlanToolbar />
          <FloorPlanCanvas
            key={`${plan.room.id}:${layout.version}`}
            roomName={plan.room.name}
            layout={layout}
            pcs={plan.pcs}
          />
          <FloorPlanLegend pcs={plan.pcs} />
          <PcUnitList roomName={plan.room.name} pcs={plan.pcs} />
        </>
      )}
    </div>
  )
}
