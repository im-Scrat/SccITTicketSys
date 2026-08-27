import { Archive, ArrowLeft, Pencil, RotateCcw } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  Alert,
  Badge,
  Button,
  ConfirmDialog,
  EmptyState,
  Skeleton,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tabs,
  Tr,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDate, formatDateTime } from '@/lib/datetime'
import { AssetHistoryTimeline } from '../components/AssetHistoryTimeline'
import { AttachmentGallery } from '../components/AttachmentGallery'
import { PcUnitFormDrawer } from '../components/PcUnitFormDrawer'
import { QrCodePanel } from '../components/QrCodePanel'
import { SpecificationEditor } from '../components/SpecificationEditor'
import { useArchivePcUnit, useRestorePcUnit } from '../hooks/mutations'
import { usePcUnit } from '../hooks/queries'
import type { PcUnitDetail } from '../types'

type TabValue =
  | 'general'
  | 'specifications'
  | 'components'
  | 'qr'
  | 'maintenance'
  | 'timeline'
  | 'attachments'
  | 'warranty'

/**
 * The consolidated PC info view (SRS FR-PC-006): identity, network, the
 * specification editor, installed hardware, QR, maintenance and the timeline.
 *
 * The specification tab is the real editor (FR-PC-003) — this is the one place a
 * machine's CPU, RAM, storage, GPU, OS and BIOS are maintained, and every change
 * is audited with a field-level diff that shows up on the timeline.
 */
export default function PcUnitDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()

  const { data, isLoading, isError } = usePcUnit(id)
  const pcUnit = data?.data

  useDocumentMeta({ title: pcUnit ? pcUnit.pc_name : 'PC unit' })

  const [tab, setTab] = useState<TabValue>('general')
  const [editing, setEditing] = useState(false)
  const [archiving, setArchiving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const archive = useArchivePcUnit()
  const restore = useRestorePcUnit()

  const canUpdate = hasPermission('assets.update')
  const canDelete = hasPermission('assets.delete')

  if (isError) {
    return (
      <Alert tone="error" title="This PC unit could not be loaded">
        It may have been removed.{' '}
        <Link to="/app/assets/list?tab=pc-units">Back to the directory</Link>.
      </Alert>
    )
  }

  if (isLoading || !pcUnit) {
    return (
      <div className="flex flex-col gap-6">
        <Skeleton className="h-14 w-72 rounded-md" />
        <Skeleton className="h-40 rounded-lg" />
        <Skeleton className="h-96 rounded-lg" />
      </div>
    )
  }

  const runArchive = async () => {
    setError(null)
    try {
      await archive.mutateAsync(pcUnit.id)
      setArchiving(false)
      navigate('/app/assets/list?tab=pc-units')
    } catch (caught) {
      setError(getErrorMessage(caught))
      setArchiving(false)
    }
  }

  const runRestore = async () => {
    setError(null)
    try {
      await restore.mutateAsync(pcUnit.id)
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const tabs: Array<{ value: TabValue; label: string; count?: number }> = [
    { value: 'general', label: 'General' },
    { value: 'specifications', label: 'Specifications' },
    { value: 'components', label: 'Installed hardware', count: pcUnit.installations?.length },
    { value: 'qr', label: 'QR code' },
    { value: 'maintenance', label: 'Maintenance', count: pcUnit.maintenance?.length },
    { value: 'timeline', label: 'Timeline' },
    { value: 'attachments', label: 'Attachments', count: pcUnit.attachments?.length },
    { value: 'warranty', label: 'Warranty' },
  ]

  return (
    <div className="flex flex-col gap-8">
      <Link
        to="/app/assets/list?tab=pc-units"
        className="inline-flex items-center gap-3 text-sm font-semibold text-primary-strong"
      >
        <ArrowLeft size={32} aria-hidden="true" />
        Back to the directory
      </Link>

      {error && <Alert tone="error">{error}</Alert>}

      {pcUnit.archived && (
        <Alert tone="warning" title="This PC unit is archived">
          Its specification, installations and history are intact and it can be restored.
        </Alert>
      )}

      <header className="flex flex-wrap items-start justify-between gap-6">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-4">
            <h1 className="text-2xl font-bold text-ink-strong">{pcUnit.pc_name}</h1>
            <Badge tone="neutral">{pcUnit.status_label}</Badge>
            {pcUnit.archived && <Badge tone="neutral">Archived</Badge>}
          </div>
          <p className="mt-2 font-mono text-base text-muted">{pcUnit.unit_code}</p>
        </div>

        <div className="flex flex-wrap gap-3">
          {canUpdate && !pcUnit.archived && (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil size={32} aria-hidden="true" />
              Edit
            </Button>
          )}
          {canDelete &&
            (pcUnit.archived ? (
              <Button variant="secondary" onClick={runRestore}>
                <RotateCcw size={32} aria-hidden="true" />
                Restore
              </Button>
            ) : (
              <Button variant="danger" onClick={() => setArchiving(true)}>
                <Archive size={32} aria-hidden="true" />
                Archive
              </Button>
            ))}
        </div>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(value) => setTab(value as TabValue)} />

      <section className="rounded-lg border-2 border-border bg-surface p-6 lg:p-8">
        {tab === 'general' && <GeneralTab pcUnit={pcUnit} />}

        {tab === 'specifications' && (
          <SpecificationEditor
            pcUnitId={pcUnit.id}
            specification={pcUnit.specification}
            canEdit={canUpdate && !pcUnit.archived}
          />
        )}

        {tab === 'components' && <InstalledTab pcUnit={pcUnit} />}

        {tab === 'qr' && <QrCodePanel kind="pc-units" id={pcUnit.id} canManage={canUpdate} />}

        {tab === 'maintenance' && <MaintenanceTab pcUnit={pcUnit} />}

        {tab === 'timeline' && <AssetHistoryTimeline kind="pc-units" id={pcUnit.id} />}

        {tab === 'attachments' && (
          <AttachmentGallery kind="pc-units" id={pcUnit.id} canManage={canUpdate} />
        )}

        {tab === 'warranty' && <WarrantyTab pcUnit={pcUnit} />}
      </section>

      <PcUnitFormDrawer open={editing} onClose={() => setEditing(false)} pcUnit={pcUnit} />

      <ConfirmDialog
        open={archiving}
        onClose={() => setArchiving(false)}
        onConfirm={runArchive}
        title="Archive this PC unit?"
        confirmLabel="Archive"
        tone="danger"
        description="It leaves the active register but keeps its specification and full history. A PC that still holds installed components cannot be archived."
      />
    </div>
  )
}

function GeneralTab({ pcUnit }: { pcUnit: PcUnitDetail }) {
  return (
    <dl className="grid gap-x-10 gap-y-6 sm:grid-cols-2 xl:grid-cols-3">
      <Detail label="Unit code" value={pcUnit.unit_code} mono />
      <Detail label="PC name" value={pcUnit.pc_name} />
      <Detail label="Asset tag" value={pcUnit.asset_tag} mono />
      <Detail label="Hostname" value={pcUnit.hostname} mono />
      <Detail label="Serial number" value={pcUnit.serial_number} mono />
      <Detail label="Brand" value={pcUnit.brand} />
      <Detail label="Model" value={pcUnit.model} />
      <Detail label="Status" value={pcUnit.status_label} />
      <Detail label="Condition" value={pcUnit.condition_label} />
      <Detail label="IP address" value={pcUnit.network.ip_address} mono />
      <Detail label="MAC address" value={pcUnit.network.mac_address} mono />
      <Detail label="Building" value={pcUnit.building?.name} />
      <Detail label="Room" value={pcUnit.room?.name} />
      <Detail label="QR identifier" value={pcUnit.qr_identifier} mono />
      <Detail label="Registered by" value={pcUnit.created_by} />
      <Detail label="Registered" value={formatDateTime(pcUnit.created_at)} />
      <Detail label="Last updated by" value={pcUnit.updated_by} />
      <Detail label="Last updated" value={formatDateTime(pcUnit.updated_at)} />

      {pcUnit.notes && (
        <div className="sm:col-span-2 xl:col-span-3">
          <dt className="text-xs font-semibold uppercase tracking-wide text-muted">Notes</dt>
          <dd className="mt-1 text-base text-ink measure">{pcUnit.notes}</dd>
        </div>
      )}
    </dl>
  )
}

/**
 * The authoritative record of what is physically inside this machine
 * (FR-PC-004), as opposed to the editable spec snapshot on the previous tab.
 */
function InstalledTab({ pcUnit }: { pcUnit: PcUnitDetail }) {
  const installations = pcUnit.installations ?? []

  if (installations.length === 0) {
    return (
      <EmptyState
        title="No components recorded"
        description="Serialized parts fitted into this machine appear here. This is the authoritative record — the Specifications tab is the editable summary."
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>Component</Th>
          <Th>Category</Th>
          <Th>Status</Th>
          <Th>Installed</Th>
          <Th>Removed</Th>
        </Tr>
      </THead>
      <TBody>
        {installations.map((row) => (
          <Tr key={row.id}>
            <Td>
              {row.asset ? (
                <Link
                  to={`/app/assets/${row.asset.id}`}
                  className="font-semibold text-primary-strong underline-offset-4 hover:underline"
                >
                  {row.asset.name}
                </Link>
              ) : (
                '—'
              )}
              {row.asset && (
                <span className="block font-mono text-xs text-muted">{row.asset.asset_tag}</span>
              )}
            </Td>
            <Td className="text-sm text-muted">{row.asset?.category_label ?? '—'}</Td>
            <Td>
              <Badge tone={row.current ? 'success' : 'neutral'}>
                {row.installation_status_label}
              </Badge>
            </Td>
            <Td className="text-sm tnum">{formatDate(row.installed_at)}</Td>
            <Td className="text-sm tnum">{row.removed_at ? formatDate(row.removed_at) : '—'}</Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

function MaintenanceTab({ pcUnit }: { pcUnit: PcUnitDetail }) {
  const records = pcUnit.maintenance ?? []

  if (records.length === 0) {
    return (
      <EmptyState
        title="No maintenance recorded"
        description="Service visits logged against this machine will appear here."
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>Work</Th>
          <Th>Status</Th>
          <Th>Technician</Th>
          <Th>Date</Th>
          <Th className="text-right">Downtime</Th>
        </Tr>
      </THead>
      <TBody>
        {records.map((record) => (
          <Tr key={record.id}>
            <Td>
              <span className="block font-semibold text-ink">{record.title}</span>
              {record.resolution && (
                <span className="block text-sm text-muted">{record.resolution}</span>
              )}
            </Td>
            <Td>
              <Badge tone="neutral">{record.status_label}</Badge>
            </Td>
            <Td className="text-sm">{record.technician ?? '—'}</Td>
            <Td className="text-sm tnum">
              {formatDate(record.completed_at ?? record.maintenance_date)}
            </Td>
            <Td className="text-right text-sm tnum">
              {record.downtime_minutes !== null ? `${record.downtime_minutes} min` : '—'}
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

function WarrantyTab({ pcUnit }: { pcUnit: PcUnitDetail }) {
  const days = pcUnit.warranty.days_remaining

  return (
    <div className="flex flex-col gap-8">
      {pcUnit.warranty.expiration ? (
        days !== null && days < 0 ? (
          <Alert tone="error" title="Warranty has lapsed">
            Cover ended on {formatDate(pcUnit.warranty.expiration)}.
          </Alert>
        ) : days !== null && days <= 90 ? (
          <Alert tone="warning" title="Warranty expiring soon">
            {days} days of cover remain, until {formatDate(pcUnit.warranty.expiration)}.
          </Alert>
        ) : (
          <Alert tone="success" title="Under warranty">
            Cover runs until {formatDate(pcUnit.warranty.expiration)}.
          </Alert>
        )
      ) : (
        <Alert tone="info" title="No warranty recorded">
          Add a warranty expiry date on the edit form to track cover for this machine.
        </Alert>
      )}

      <dl className="grid gap-x-10 gap-y-6 sm:grid-cols-2 xl:grid-cols-3">
        <Detail label="Purchase date" value={formatDate(pcUnit.purchase.date)} />
        <Detail label="Warranty expires" value={formatDate(pcUnit.warranty.expiration)} />
        <Detail label="Days remaining" value={days !== null ? String(days) : null} />
      </dl>
    </div>
  )
}

function Detail({
  label,
  value,
  mono,
}: {
  label: string
  value: string | null | undefined
  mono?: boolean
}) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{label}</dt>
      <dd className={mono ? 'mt-1 font-mono text-base text-ink' : 'mt-1 text-base text-ink'}>
        {value ?? <span className="text-muted">Not recorded</span>}
      </dd>
    </div>
  )
}
