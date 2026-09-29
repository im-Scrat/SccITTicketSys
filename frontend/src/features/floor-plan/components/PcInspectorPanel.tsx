import { AlertCircle, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Alert, Badge, Button, EmptyState, Skeleton, Surface } from '@/components/ui'
import { usePcUnit } from '@/features/assets/hooks/queries'
import { ChecklistPanel } from '@/features/maintenance/components/ChecklistPanel'
import {
  MaintenanceStatusBadge,
  MaintenanceTypeBadge,
} from '@/features/maintenance/components/MaintenanceBadges'
import { HardwareReplacementPanel } from '@/features/maintenance/components/HardwareReplacementPanel'
import { MaintenanceNotes } from '@/features/maintenance/components/MaintenanceNotes'
import { RepairEvidenceGallery } from '@/features/maintenance/components/RepairEvidenceGallery'
import type { MaintenanceDetail } from '@/features/maintenance/types'
import { formatDate, formatDateTime } from '@/lib/datetime'
import { usePcMaintenanceHistory } from '../hooks/queries'

/**
 * The real story behind one PC on the plan (WP-G) — identity, network,
 * specification, location, its active ticket if any, and every maintenance
 * visit against it in full, not the lossy projections the timeline and the
 * Assets detail page's own summary tab show.
 *
 * **A non-modal side region, not a dialog.** `Drawer`/`Modal` in this app trap
 * no focus and restore none on close (a gap the WP-C acceptance checkpoint
 * found and deliberately routed around, not fixed here either — see that
 * finding). Rendering as an ordinary, dismissible section in the page's own
 * flow sidesteps the gap entirely: there is nothing modal to trap focus out
 * of, arrow/tab keys behave exactly as they do anywhere else on the page, and
 * the map, toolbar and placement controls above stay fully operable while
 * this is open.
 *
 * **Read-only.** Every reused maintenance sub-panel (`ChecklistPanel`,
 * `RepairEvidenceGallery`, `HardwareReplacementPanel`, `MaintenanceNotes`) is
 * passed `false` for its edit/manage/record/add prop — the floor plan shows
 * the record; changing one belongs to the Maintenance module itself, where a
 * technician's own work actually happens.
 */
export function PcInspectorPanel({ pcId, onClose }: { pcId: string; onClose: () => void }) {
  const pcUnit = usePcUnit(pcId)
  const history = usePcMaintenanceHistory(pcId)

  return (
    <Surface
      aria-label={
        pcUnit.data?.data.pc_name ? `${pcUnit.data.data.pc_name} details` : 'Unit details'
      }
      className="flex flex-col gap-6 p-6 sm:p-8"
    >
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          {pcUnit.isLoading ? (
            <Skeleton className="h-8 w-48" />
          ) : pcUnit.data ? (
            <>
              <h2 className="text-xl font-bold text-ink-strong">{pcUnit.data.data.pc_name}</h2>
              <p className="mt-1 font-mono text-sm text-muted">{pcUnit.data.data.unit_code}</p>
            </>
          ) : (
            <h2 className="text-xl font-bold text-ink-strong">Unit details</h2>
          )}
        </div>
        <Button variant="ghost" size="sm" onClick={onClose} aria-label="Close unit details">
          <X size={20} aria-hidden="true" />
          Close
        </Button>
      </div>

      {pcUnit.isError && (
        <Alert tone="error" title="This unit could not be loaded">
          It may have been archived since the plan was opened.
        </Alert>
      )}

      {pcUnit.isLoading && (
        <div className="flex flex-col gap-4" aria-busy="true" aria-label="Loading unit details">
          <Skeleton className="h-24 rounded-lg" />
          <Skeleton className="h-40 rounded-lg" />
        </div>
      )}

      {pcUnit.data && <IdentitySection pcUnit={pcUnit.data.data} />}

      {pcUnit.data && (
        <section aria-labelledby="fp-inspector-maintenance-heading">
          <h3
            id="fp-inspector-maintenance-heading"
            className="text-sm font-semibold text-ink-strong"
          >
            Maintenance history
            {history.data && (
              <span className="tnum ml-2 font-normal text-muted">({history.data.length})</span>
            )}
          </h3>

          {history.isLoading && (
            <div
              className="mt-3 flex flex-col gap-2"
              aria-busy="true"
              aria-label="Loading maintenance history"
            >
              <Skeleton className="h-16 rounded-md" />
              <Skeleton className="h-16 rounded-md" />
            </div>
          )}

          {history.isError && (
            <Alert tone="error" className="mt-3">
              The maintenance history could not be loaded.
            </Alert>
          )}

          {history.data && history.data.length === 0 && (
            <EmptyState
              className="mt-3"
              icon={<AlertCircle size={22} />}
              title="No maintenance recorded"
              description="Service visits logged against this machine will appear here, in full."
            />
          )}

          {history.data && history.data.length > 0 && (
            <ul className="mt-3 flex flex-col gap-3">
              {history.data.map((record) => (
                <MaintenanceRecordEntry key={record.id} record={record} />
              ))}
            </ul>
          )}
        </section>
      )}
    </Surface>
  )
}

function IdentitySection({ pcUnit }: { pcUnit: import('@/features/assets/types').PcUnitDetail }) {
  const underMaintenance = pcUnit.status === 'under_maintenance'

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone="neutral">{pcUnit.status_label}</Badge>
        {underMaintenance && (
          <span className="text-xs font-medium text-warning-strong">
            Currently under maintenance
          </span>
        )}
      </div>

      <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <Fact label="Asset tag" value={pcUnit.asset_tag} mono />
        <Fact label="Hostname" value={pcUnit.hostname} mono />
        <Fact label="IP address" value={pcUnit.network.ip_address} mono />
        <Fact label="MAC address" value={pcUnit.network.mac_address} mono />
        <Fact label="Building" value={pcUnit.building?.name ?? null} />
        <Fact label="Floor" value={pcUnit.floor?.name ?? null} />
        <Fact label="Room" value={pcUnit.room?.name ?? null} />
        <Fact label="Condition" value={pcUnit.condition_label} />
      </dl>

      {pcUnit.specification && (
        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 border-t border-border pt-4 text-sm">
          <Fact label="CPU" value={pcUnit.specification.cpu} />
          <Fact label="RAM" value={pcUnit.specification.ram} />
          <Fact label="Primary storage" value={pcUnit.specification.storage_primary} />
          <Fact label="GPU" value={pcUnit.specification.gpu} />
          <Fact label="Operating system" value={pcUnit.specification.operating_system} />
          <Fact label="Monitor" value={pcUnit.specification.monitor} />
        </dl>
      )}

      <div className="border-t border-border pt-4">
        <h3 className="text-sm font-semibold text-ink-strong">Active ticket</h3>
        {pcUnit.active_tickets.length === 0 ? (
          <p className="mt-2 text-sm text-muted">No open ticket against this machine.</p>
        ) : (
          <ul className="mt-2 flex flex-col gap-2">
            {pcUnit.active_tickets.map((ticket) => (
              <li key={ticket.id}>
                <Link
                  to={`/app/tickets/manage/${ticket.id}`}
                  className="block rounded-md border border-border p-3 text-sm hover:bg-surface-sunken"
                >
                  <span className="font-semibold text-primary-strong">{ticket.title}</span>
                  <span className="mt-1 flex flex-wrap gap-x-3 text-xs text-muted">
                    {ticket.status && <span>{ticket.status}</span>}
                    {ticket.priority && <span>{ticket.priority} priority</span>}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

function Fact({ label, value, mono }: { label: string; value: string | null; mono?: boolean }) {
  return (
    <div>
      <dt className="text-xs font-semibold tracking-wide text-muted uppercase">{label}</dt>
      <dd className={mono ? 'mt-1 font-mono text-ink' : 'mt-1 text-ink'}>
        {value ?? <span className="text-muted">Not recorded</span>}
      </dd>
    </div>
  )
}

/**
 * One visit, collapsed to a summary row by default. `<details>` rather than a
 * component-managed open flag: free keyboard and screen-reader semantics,
 * and several records can be open at once without extra state.
 */
function MaintenanceRecordEntry({ record }: { record: MaintenanceDetail }) {
  const requiresEvidence = !record.type.is_preventive

  return (
    <li>
      <details className="group rounded-md border border-border">
        <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 p-4 [&::-webkit-details-marker]:hidden">
          <span className="min-w-0">
            <span className="block font-semibold text-ink-strong">{record.title}</span>
            <span className="mt-1 flex flex-wrap items-center gap-2">
              <MaintenanceStatusBadge status={record.status} />
              <MaintenanceTypeBadge type={record.type} />
            </span>
          </span>
          <span className="tnum shrink-0 text-sm text-muted">
            {formatDate(record.completed_at ?? record.maintenance_date ?? record.scheduled_for)}
          </span>
        </summary>

        <div className="flex flex-col gap-6 border-t border-border p-4">
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
            <Fact label="Technician" value={record.technician?.name ?? null} />
            <Fact
              label="Downtime"
              value={record.downtime_minutes !== null ? `${record.downtime_minutes} min` : null}
            />
            <Fact
              label="Labour"
              value={record.labor_hours !== null ? `${record.labor_hours} h` : null}
            />
            <Fact label="Cost" value={record.cost !== null ? `₱${record.cost}` : null} />
          </dl>

          <Narrative label="Diagnosis" value={record.diagnosis} />
          <Narrative label="Root cause" value={record.root_cause} />
          <Narrative label="Resolution" value={record.resolution} />
          <Narrative label="Preventive recommendation" value={record.preventive_recommendation} />

          {record.pc_state_before && (
            <Alert tone="info" title="What this visit displaced">
              The machine was <strong>{record.pc_state_before.status_label}</strong> before this
              visit began.
            </Alert>
          )}

          {record.ticket && (
            <p className="text-sm text-ink">
              From ticket{' '}
              <Link
                to={`/app/tickets/manage/${record.ticket.id}`}
                className="font-semibold text-primary-strong underline-offset-4 hover:underline"
              >
                {record.ticket.number}
              </Link>
              {record.ticket.title ? ` — ${record.ticket.title}` : null}
            </p>
          )}

          <ChecklistPanel recordId={record.id} items={record.checklist_items} canEdit={false} />
          <RepairEvidenceGallery
            recordId={record.id}
            evidence={record.evidence}
            canManage={false}
            requiresEvidence={requiresEvidence}
          />
          <HardwareReplacementPanel
            recordId={record.id}
            replacements={record.hardware_replacements}
            canRecord={false}
          />
          <MaintenanceNotes recordId={record.id} notes={record.notes} canAdd={false} />

          <p className="text-xs text-muted">
            {record.scheduled_for && `Scheduled ${formatDateTime(record.scheduled_for)}. `}
            {record.started_at && `Started ${formatDateTime(record.started_at)}. `}
            {record.completed_at && `Completed ${formatDateTime(record.completed_at)}.`}
          </p>
        </div>
      </details>
    </li>
  )
}

function Narrative({ label, value }: { label: string; value: string | null }) {
  if (!value) return null

  return (
    <div>
      <h4 className="text-xs font-semibold tracking-wide text-muted uppercase">{label}</h4>
      <p className="mt-1 text-sm whitespace-pre-wrap text-ink">{value}</p>
    </div>
  )
}
