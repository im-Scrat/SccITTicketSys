import { Archive, ArrowLeft, ArrowRightLeft, Pencil, RotateCcw, Tag, UserCog } from 'lucide-react'
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
import {
  AssignTechnicianDialog,
  ChangeStatusDialog,
  TransferAssetDialog,
} from '../components/AssetActionDialogs'
import { AssetFormDrawer } from '../components/AssetFormDrawer'
import { AssetHistoryTimeline } from '../components/AssetHistoryTimeline'
import { AssetStatusBadge } from '../components/AssetStatusBadge'
import { AttachmentGallery } from '../components/AttachmentGallery'
import { QrCodePanel } from '../components/QrCodePanel'
import { useArchiveAsset, useRestoreAsset } from '../hooks/mutations'
import { useAsset } from '../hooks/queries'
import type { AssetDetail } from '../types'

type TabValue =
  | 'general'
  | 'specifications'
  | 'components'
  | 'qr'
  | 'maintenance'
  | 'transfers'
  | 'timeline'
  | 'attachments'
  | 'warranty'
  | 'custodian'

/**
 * The asset's complete management page (SRS FR-AST-002, FR-PC-006).
 *
 * Ten tabs, one record. This is deliberately the *central* page for an asset
 * rather than a read-only summary: everything the later Maintenance, Ticketing,
 * QR-verification and Analytics phases need to attach to already has a home
 * here, so those phases add content to a tab instead of redesigning the screen.
 *
 * Tabs backed by tables that exist but are not yet written to by a shipped
 * module (maintenance, tickets) render their real data and a teaching empty
 * state otherwise — never a dead link to a "coming soon" page.
 */
export default function AssetDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()

  const { data, isLoading, isError } = useAsset(id)
  const asset = data?.data
  const transitions = data?.meta?.transitions ?? []

  useDocumentMeta({ title: asset ? asset.display_name : 'Asset' })

  const [tab, setTab] = useState<TabValue>('general')
  const [editing, setEditing] = useState(false)
  const [dialog, setDialog] = useState<'status' | 'transfer' | 'assign' | 'archive' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const archive = useArchiveAsset()
  const restore = useRestoreAsset()

  const canUpdate = hasPermission('assets.update')
  const canTransfer = hasPermission('assets.transfer')
  const canDelete = hasPermission('assets.delete')

  if (isError) {
    return (
      <Alert tone="error" title="This asset could not be loaded">
        It may have been removed. <Link to="/app/assets/list">Back to the directory</Link>.
      </Alert>
    )
  }

  if (isLoading || !asset) {
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
      await archive.mutateAsync(asset.id)
      setDialog(null)
      navigate('/app/assets/list')
    } catch (caught) {
      setError(getErrorMessage(caught))
      setDialog(null)
    }
  }

  const runRestore = async () => {
    setError(null)
    try {
      await restore.mutateAsync(asset.id)
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const tabs: Array<{ value: TabValue; label: string; count?: number }> = [
    { value: 'general', label: 'General' },
    { value: 'specifications', label: 'Specifications' },
    { value: 'components', label: 'Installed in', count: asset.installations?.length },
    { value: 'qr', label: 'QR code' },
    { value: 'maintenance', label: 'Maintenance', count: asset.maintenance?.length },
    { value: 'transfers', label: 'Transfers', count: asset.transfers?.length },
    { value: 'timeline', label: 'Timeline' },
    { value: 'attachments', label: 'Attachments', count: asset.attachments?.length },
    { value: 'warranty', label: 'Warranty' },
    { value: 'custodian', label: 'Custodian' },
  ]

  return (
    <div className="flex flex-col gap-8">
      <Link
        to="/app/assets/list"
        className="inline-flex items-center gap-3 text-sm font-semibold text-primary-strong"
      >
        <ArrowLeft size={32} aria-hidden="true" />
        Back to the directory
      </Link>

      {error && <Alert tone="error">{error}</Alert>}

      {asset.archived && (
        <Alert tone="warning" title="This asset is archived">
          Its history is intact. Restoring returns it to the register with the status it held when
          it was archived.
        </Alert>
      )}

      <header className="flex flex-wrap items-start justify-between gap-6">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-4">
            <h1 className="text-2xl font-bold text-ink-strong">{asset.display_name}</h1>
            <AssetStatusBadge label={asset.status_label} tone={asset.tone} />
            {asset.archived && <Badge tone="neutral">Archived</Badge>}
          </div>
          <p className="mt-2 font-mono text-base text-muted">{asset.asset_tag}</p>
        </div>

        <div className="flex flex-wrap gap-3">
          {canUpdate && !asset.archived && (
            <>
              <Button variant="secondary" onClick={() => setEditing(true)}>
                <Pencil size={32} aria-hidden="true" />
                Edit
              </Button>
              <Button variant="secondary" onClick={() => setDialog('status')}>
                <Tag size={32} aria-hidden="true" />
                Change status
              </Button>
              <Button variant="secondary" onClick={() => setDialog('assign')}>
                <UserCog size={32} aria-hidden="true" />
                Assign
              </Button>
            </>
          )}
          {canTransfer && !asset.archived && (
            <Button variant="secondary" onClick={() => setDialog('transfer')}>
              <ArrowRightLeft size={32} aria-hidden="true" />
              Transfer
            </Button>
          )}
          {canDelete &&
            (asset.archived ? (
              <Button variant="secondary" onClick={runRestore}>
                <RotateCcw size={32} aria-hidden="true" />
                Restore
              </Button>
            ) : (
              <Button variant="danger" onClick={() => setDialog('archive')}>
                <Archive size={32} aria-hidden="true" />
                Archive
              </Button>
            ))}
        </div>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(value) => setTab(value as TabValue)} />

      <section className="rounded-lg border-2 border-border bg-surface p-6 lg:p-8">
        {tab === 'general' && <GeneralTab asset={asset} />}

        {tab === 'specifications' && <CatalogSpecTab asset={asset} />}

        {tab === 'components' && <InstalledTab asset={asset} />}

        {tab === 'qr' && <QrCodePanel kind="assets" id={asset.id} canManage={canUpdate} />}

        {tab === 'maintenance' && <MaintenanceTab asset={asset} />}

        {tab === 'transfers' && <TransfersTab asset={asset} />}

        {tab === 'timeline' && <AssetHistoryTimeline kind="assets" id={asset.id} />}

        {tab === 'attachments' && (
          <AttachmentGallery kind="assets" id={asset.id} canManage={canUpdate} />
        )}

        {tab === 'warranty' && <WarrantyTab asset={asset} />}

        {tab === 'custodian' && (
          <CustodianTab asset={asset} canAssign={canUpdate} onAssign={() => setDialog('assign')} />
        )}
      </section>

      <AssetFormDrawer open={editing} onClose={() => setEditing(false)} asset={asset} />

      <ChangeStatusDialog
        open={dialog === 'status'}
        onClose={() => setDialog(null)}
        asset={asset}
        transitions={transitions}
      />
      <TransferAssetDialog
        open={dialog === 'transfer'}
        onClose={() => setDialog(null)}
        asset={asset}
      />
      <AssignTechnicianDialog
        open={dialog === 'assign'}
        onClose={() => setDialog(null)}
        asset={asset}
      />

      <ConfirmDialog
        open={dialog === 'archive'}
        onClose={() => setDialog(null)}
        onConfirm={runArchive}
        title="Archive this asset?"
        confirmLabel="Archive"
        tone="danger"
        description="It leaves the active register but keeps its full history, and can be restored at any time. An asset still installed inside a PC cannot be archived."
      />
    </div>
  )
}

/* ------------------------------------------------------------------ tabs */

function GeneralTab({ asset }: { asset: AssetDetail }) {
  return (
    <dl className="grid gap-x-10 gap-y-6 sm:grid-cols-2 xl:grid-cols-3">
      <Detail label="Asset tag" value={asset.asset_tag} mono />
      <Detail label="Name" value={asset.name ?? `${asset.display_name} (from catalog)`} />
      <Detail label="Serial number" value={asset.serial_number} mono />
      <Detail label="Barcode" value={asset.barcode} mono />
      <Detail label="Category" value={asset.category_label} />
      <Detail label="Brand" value={asset.manufacturer} />
      <Detail label="Model" value={asset.model?.name} />
      <Detail label="Condition" value={asset.condition_label} />
      <Detail label="Status" value={asset.status_label} />
      <Detail label="Building" value={asset.building?.name} />
      <Detail label="Room" value={asset.room?.name} />
      <Detail label="Custodian" value={asset.technician?.name} />
      <Detail label="Supplier" value={asset.supplier?.name} />
      <Detail label="Registered by" value={asset.created_by} />
      <Detail label="Registered" value={formatDateTime(asset.created_at)} />
      <Detail label="Last updated by" value={asset.updated_by} />
      <Detail label="Last updated" value={formatDateTime(asset.updated_at)} />

      {asset.notes && (
        <div className="sm:col-span-2 xl:col-span-3">
          <dt className="text-xs font-semibold uppercase tracking-wide text-muted">Notes</dt>
          <dd className="mt-1 text-base text-ink measure">{asset.notes}</dd>
        </div>
      )}
    </dl>
  )
}

/**
 * A standalone asset's specification comes from its **catalog model** — the
 * per-machine editable snapshot belongs to PC units (FR-PC-003), so this tab
 * shows the catalog's structured JSON rather than pretending to offer an editor
 * that has nowhere to write.
 */
function CatalogSpecTab({ asset }: { asset: AssetDetail }) {
  const specs = asset.model?.specifications

  const entries =
    specs && typeof specs === 'object' && !Array.isArray(specs)
      ? Object.entries(specs as Record<string, unknown>)
      : []

  return (
    <div className="flex flex-col gap-6">
      <dl className="grid gap-x-10 gap-y-6 sm:grid-cols-2 xl:grid-cols-3">
        <Detail label="Catalog model" value={asset.model?.name} />
        <Detail label="Model number" value={asset.model?.number} mono />
        <Detail label="Component" value={asset.component} />
        <Detail label="Category" value={asset.category_label} />
        <Detail label="Brand" value={asset.manufacturer} />
      </dl>

      {entries.length > 0 ? (
        <section>
          <h3 className="text-base font-bold text-ink-strong">Catalog specifications</h3>
          <dl className="mt-4 grid gap-x-10 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">
            {entries.map(([key, value]) => (
              <div key={key}>
                <dt className="text-xs font-semibold uppercase tracking-wide text-muted">
                  {key.replace(/_/g, ' ')}
                </dt>
                <dd className="mt-1 text-base text-ink">{String(value)}</dd>
              </div>
            ))}
          </dl>
        </section>
      ) : (
        <p className="text-base text-muted measure">
          This catalog model carries no structured specifications. Per-machine hardware details are
          recorded on PC units, which have their own specification editor.
        </p>
      )}
    </div>
  )
}

function InstalledTab({ asset }: { asset: AssetDetail }) {
  const installations = asset.installations ?? []

  if (installations.length === 0) {
    return (
      <EmptyState
        title="Not installed in a PC"
        description="When this asset is fitted into a machine, the installation appears here and the asset can no longer be archived until it is removed."
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>PC unit</Th>
          <Th>Status</Th>
          <Th>Installed</Th>
          <Th>Removed</Th>
          <Th>By</Th>
        </Tr>
      </THead>
      <TBody>
        {installations.map((row) => (
          <Tr key={row.id}>
            <Td>
              {row.pc_unit ? (
                <Link
                  to={`/app/assets/pc-units/${row.pc_unit.id}`}
                  className="font-semibold text-primary-strong underline-offset-4 hover:underline"
                >
                  {row.pc_unit.pc_name}
                </Link>
              ) : (
                '—'
              )}
            </Td>
            <Td>
              <Badge tone={row.current ? 'success' : 'neutral'}>
                {row.installation_status_label}
              </Badge>
            </Td>
            <Td className="text-sm tnum">{formatDate(row.installed_at)}</Td>
            <Td className="text-sm tnum">{row.removed_at ? formatDate(row.removed_at) : '—'}</Td>
            <Td className="text-sm">{row.installed_by ?? '—'}</Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

function MaintenanceTab({ asset }: { asset: AssetDetail }) {
  const records = asset.maintenance ?? []

  if (records.length === 0) {
    return (
      <EmptyState
        title="No maintenance recorded"
        description="Service visits logged against this asset will appear here, with their diagnosis, resolution and downtime."
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

function TransfersTab({ asset }: { asset: AssetDetail }) {
  const transfers = asset.transfers ?? []

  if (transfers.length === 0) {
    return (
      <EmptyState
        title="Never moved"
        description="Every room-to-room move is recorded here with who moved it and why."
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>From</Th>
          <Th>To</Th>
          <Th>Reason</Th>
          <Th>By</Th>
          <Th>When</Th>
        </Tr>
      </THead>
      <TBody>
        {transfers.map((transfer) => (
          <Tr key={transfer.id}>
            <Td className="text-sm">{transfer.from?.label ?? 'Unassigned'}</Td>
            <Td className="text-sm font-semibold text-ink">{transfer.to?.label ?? 'Unassigned'}</Td>
            <Td className="text-sm text-muted">{transfer.reason ?? '—'}</Td>
            <Td className="text-sm">{transfer.transferred_by ?? '—'}</Td>
            <Td className="text-sm tnum">{formatDateTime(transfer.transferred_at)}</Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

function WarrantyTab({ asset }: { asset: AssetDetail }) {
  const { warranty, purchase } = asset
  const days = warranty.days_remaining

  return (
    <div className="flex flex-col gap-8">
      {warranty.expiration ? (
        days !== null && days < 0 ? (
          <Alert tone="error" title="Warranty has lapsed">
            Cover ended on {formatDate(warranty.expiration)} — {Math.abs(days)} days ago.
          </Alert>
        ) : days !== null && days <= 90 ? (
          <Alert tone="warning" title="Warranty expiring soon">
            {days} days of cover remain, until {formatDate(warranty.expiration)}.
          </Alert>
        ) : (
          <Alert tone="success" title="Under warranty">
            Cover runs until {formatDate(warranty.expiration)}.
          </Alert>
        )
      ) : (
        <Alert tone="info" title="No warranty recorded">
          Add a warranty expiry date to have this asset appear in the expiring-soon list.
        </Alert>
      )}

      <dl className="grid gap-x-10 gap-y-6 sm:grid-cols-2 xl:grid-cols-3">
        <Detail label="Purchase date" value={formatDate(purchase.date)} />
        <Detail
          label="Purchase price"
          value={purchase.price !== null ? String(purchase.price) : null}
        />
        <Detail label="Warranty expires" value={formatDate(warranty.expiration)} />
        <Detail label="Days remaining" value={days !== null ? String(days) : null} />
        <Detail label="Supplier" value={asset.supplier?.name} />
        <Detail label="Supplier contact" value={asset.supplier?.contact_person} />
        <Detail label="Supplier phone" value={asset.supplier?.contact_number} />
        <Detail label="Supplier email" value={asset.supplier?.email} />
      </dl>
    </div>
  )
}

function CustodianTab({
  asset,
  canAssign,
  onAssign,
}: {
  asset: AssetDetail
  canAssign: boolean
  onAssign: () => void
}) {
  return (
    <div className="flex flex-col gap-6">
      {asset.technician ? (
        <dl className="grid gap-x-10 gap-y-6 sm:grid-cols-2">
          <Detail label="Custodian" value={asset.technician.name} />
          <Detail label="Email" value={asset.technician.email ?? null} />
        </dl>
      ) : (
        <EmptyState
          title="No custodian assigned"
          description="Assigning a technician or administrator records who is responsible for this equipment. It does not change the asset's lifecycle status."
        />
      )}

      <p className="text-sm text-muted measure">
        Every assignment and hand-back is recorded on the timeline, so the chain of responsibility
        stays auditable.
      </p>

      {canAssign && !asset.archived && (
        <div>
          <Button onClick={onAssign}>
            <UserCog size={32} aria-hidden="true" />
            {asset.technician ? 'Change custodian' : 'Assign custodian'}
          </Button>
        </div>
      )}
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
