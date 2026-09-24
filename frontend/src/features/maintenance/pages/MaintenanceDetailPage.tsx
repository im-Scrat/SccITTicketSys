import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { AuditTimeline } from '@/components/AuditTimeline'
import { Alert, Button, Skeleton, Surface, Tabs } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDateTime } from '@/lib/datetime'
import { ChecklistPanel } from '../components/ChecklistPanel'
import { HardwareReplacementPanel } from '../components/HardwareReplacementPanel'
import {
  MaintenanceStatusBadge,
  MaintenanceTypeBadge,
  OverdueBadge,
} from '../components/MaintenanceBadges'
import { MaintenanceFormDrawer } from '../components/MaintenanceFormDrawer'
import { MaintenanceNotes } from '../components/MaintenanceNotes'
import { MaintenanceStatusActions } from '../components/MaintenanceStatusActions'
import { RepairEvidenceGallery } from '../components/RepairEvidenceGallery'
import { useUpdateMaintenance } from '../hooks/mutations'
import { useMaintenanceAudit, useMaintenanceOptions, useMaintenanceRecord } from '../hooks/queries'

/**
 * One maintenance record — the surface a technician does the job on
 * (SRS FR-MNT-003/004/005/006).
 *
 * Every control on this page is gated by `record.abilities`, which the server
 * computed from the policy that would decide the request. Nothing here infers
 * permission from the role: a completed visit shows the same page with the
 * controls absent, because the record is then the account of what happened
 * rather than work in progress.
 *
 * A record the caller may not reach never gets here at all — the API answers 403
 * and the page shows it, which is the same answer they would get from the list.
 */
export default function MaintenanceDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const record = useMaintenanceRecord(id)
  const options = useMaintenanceOptions()
  const audit = useMaintenanceAudit(id)
  const update = useUpdateMaintenance(id ?? '')

  const [editing, setEditing] = useState(false)
  const [tab, setTab] = useState('work')

  useDocumentMeta({ title: record.data?.title ?? 'Maintenance' })

  if (record.isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-label="Loading record">
        <Skeleton className="h-10 w-64 rounded-md" />
        <Skeleton className="h-40 rounded-lg" />
        <Skeleton className="h-64 rounded-lg" />
      </div>
    )
  }

  if (record.isError || !record.data) {
    return (
      <div className="flex flex-col gap-6">
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/maintenance')}>
          <ArrowLeft size={26} aria-hidden="true" />
          My maintenance
        </Button>

        <Alert tone="error" title="This record is not available to you">
          It may belong to another technician, or it may have been archived. Records you were
          assigned or opened appear in your maintenance list.
        </Alert>
      </div>
    )
  }

  const data = record.data
  const requiresEvidence = !data.type.is_preventive

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/maintenance')}>
          <ArrowLeft size={26} aria-hidden="true" />
          My maintenance
        </Button>
      </div>

      <header className="flex flex-col gap-4">
        <div className="flex flex-wrap items-start justify-between gap-6">
          <div className="measure">
            <h1 className="text-2xl font-bold text-ink-strong">{data.title}</h1>

            <div className="mt-3 flex flex-wrap items-center gap-2">
              <MaintenanceStatusBadge status={data.status} />
              <MaintenanceTypeBadge type={data.type} />
              {data.overdue && <OverdueBadge />}
            </div>
          </div>

          {data.abilities.update && (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              Edit details
            </Button>
          )}
        </div>

        <MaintenanceStatusActions record={data} />
      </header>

      <Surface className="grid gap-6 p-6 sm:grid-cols-2 lg:grid-cols-4">
        <Fact label="Equipment" value={data.target?.identifier ?? '—'} hint={data.target?.label} />
        <Fact
          label="Location"
          value={data.location?.room ?? '—'}
          hint={data.location?.building ?? undefined}
        />
        <Fact label="Technician" value={data.technician?.name ?? 'Unassigned'} />
        <Fact
          label="Scheduled"
          value={data.scheduled_for ? formatDateTime(data.scheduled_for) : 'Not scheduled'}
        />
        <Fact label="Opened by" value={data.created_by?.name ?? '—'} />
        <Fact
          label="Started"
          value={data.started_at ? formatDateTime(data.started_at) : 'Not started'}
        />
        <Fact
          label="Completed"
          value={data.completed_at ? formatDateTime(data.completed_at) : '—'}
        />
        <Fact
          label="From ticket"
          value={data.ticket?.number ?? '—'}
          hint={data.ticket?.title ?? undefined}
        />
      </Surface>

      {data.pc_state_before && data.status.is_open && (
        <Alert tone="info" title="This machine is under maintenance">
          It will return to <strong>{data.pc_state_before.status_label}</strong> when the visit is
          completed.
        </Alert>
      )}

      <div className="flex flex-col gap-6">
        <Tabs
          tabs={[
            { value: 'work', label: 'Work' },
            { value: 'checklist', label: 'Checklist', count: data.checklist.total },
            { value: 'evidence', label: 'Evidence', count: data.evidence_count },
            { value: 'parts', label: 'Parts', count: data.hardware_replacements.length },
            { value: 'notes', label: 'Notes', count: data.note_count },
            { value: 'timeline', label: 'Timeline' },
          ]}
          value={tab}
          onChange={setTab}
        />

        {tab === 'work' && (
          <div className="flex flex-col gap-6">
            <Narrative label="Diagnosis" value={data.diagnosis} />
            <Narrative label="Root cause" value={data.root_cause} />
            <Narrative label="Resolution" value={data.resolution} />
            <Narrative label="Preventive recommendation" value={data.preventive_recommendation} />
          </div>
        )}

        {tab === 'checklist' && (
          <ChecklistPanel
            recordId={data.id}
            items={data.checklist_items}
            canEdit={data.abilities.update}
          />
        )}

        {tab === 'evidence' && (
          <RepairEvidenceGallery
            recordId={data.id}
            evidence={data.evidence}
            canManage={data.abilities.manage_evidence}
            requiresEvidence={requiresEvidence && data.status.is_open}
          />
        )}

        {tab === 'parts' && (
          <HardwareReplacementPanel
            recordId={data.id}
            replacements={data.hardware_replacements}
            canRecord={data.abilities.record_replacement}
          />
        )}

        {tab === 'notes' && (
          <MaintenanceNotes
            recordId={data.id}
            notes={data.notes}
            canAdd={data.abilities.manage_evidence}
          />
        )}

        {tab === 'timeline' && (
          <AuditTimeline
            entries={audit.data?.data}
            isLoading={audit.isLoading}
            emptyDescription="Every transition, checklist tick and piece of evidence on this visit appears here."
          />
        )}
      </div>

      <MaintenanceFormDrawer
        open={editing}
        onClose={() => setEditing(false)}
        options={options.data}
        record={data}
        onSubmit={(payload) => update.mutateAsync(payload)}
        submitting={update.isPending}
      />
    </div>
  )
}

function Fact({ label, value, hint }: { label: string; value: string; hint?: string | null }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{label}</dt>
      <dd className="mt-1 text-sm font-medium text-ink-strong">{value}</dd>
      {hint && <p className="text-xs text-muted">{hint}</p>}
    </div>
  )
}

function Narrative({ label, value }: { label: string; value: string | null }) {
  return (
    <section>
      <h2 className="text-sm font-semibold text-ink-strong">{label}</h2>
      {value ? (
        <p className="mt-2 whitespace-pre-wrap text-sm text-ink">{value}</p>
      ) : (
        <p className="mt-2 text-sm text-muted">Not recorded.</p>
      )}
    </section>
  )
}
