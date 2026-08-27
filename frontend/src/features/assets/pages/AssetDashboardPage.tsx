import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Alert, Button, Skeleton, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDate, formatRelative } from '@/lib/datetime'
import { AssetFormDrawer } from '../components/AssetFormDrawer'
import { DistributionCard, MetricCard } from '../components/AssetMetricsCards'
import { AssetStatusBadge } from '../components/AssetStatusBadge'
import { useAssetDashboard } from '../hooks/queries'
import type { DistributionRow, RecentAsset } from '../types'

/**
 * The Asset Management landing page.
 *
 * Distinct from the role dashboard at `/app`: that one answers "what should I
 * see when I sign in", assembled across every module. This one answers "what is
 * the state of the equipment register", and it is the module's front door.
 *
 * **Every figure is a link.** Each card and each distribution row navigates to
 * `/app/assets/list` with the matching filter in the URL, so a number is always
 * the start of a task rather than the end of one. Filter state living in the
 * query string is what makes those links work, and makes the resulting view
 * shareable and back-button-correct.
 */
export default function AssetDashboardPage() {
  useDocumentMeta({ title: 'Assets' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('assets.create')
  const [creating, setCreating] = useState(false)

  const { data, isLoading, isError } = useAssetDashboard()

  const go = (query: string) => navigate(`/app/assets/list?${query}`)

  if (isError) {
    return (
      <Alert tone="error" title="The asset dashboard could not be loaded">
        Refresh the page to try again.
      </Alert>
    )
  }

  return (
    <div className="flex flex-col gap-10">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">Assets</h1>
          <p className="mt-2 text-base text-muted">
            Every piece of IT equipment on the estate, and where it stands right now.
          </p>
        </div>

        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" onClick={() => go('')}>
            View all assets
          </Button>
          {canCreate && (
            <Button onClick={() => setCreating(true)}>
              <Plus size={32} aria-hidden="true" />
              Add asset
            </Button>
          )}
        </div>
      </header>

      {isLoading || !data ? (
        <DashboardSkeleton />
      ) : (
        <>
          <section aria-labelledby="asset-totals" className="flex flex-col gap-4">
            <h2 id="asset-totals" className="text-lg font-bold text-ink-strong">
              At a glance
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <MetricCard label="Total assets" value={data.summary.total} onClick={() => go('')} />
              <MetricCard
                label="Available"
                value={data.summary.available}
                onClick={() => go('status=in_stock')}
              />
              <MetricCard
                label="Assigned"
                value={data.summary.assigned}
                onClick={() => go('status=reserved')}
              />
              <MetricCard
                label="In service"
                value={data.summary.in_service}
                tone="success"
                onClick={() => go('status=deployed')}
              />
              <MetricCard
                label="Under maintenance"
                value={data.summary.maintenance}
                tone={data.summary.maintenance > 0 ? 'warning' : 'neutral'}
                onClick={() => go('status=in_repair')}
              />
              <MetricCard
                label="Out of service"
                value={data.summary.out_of_service}
                tone={data.summary.out_of_service > 0 ? 'danger' : 'neutral'}
                onClick={() => go('status=out_of_service')}
              />
              <MetricCard
                label="Warranty expiring"
                value={data.warranty_expiring.count}
                hint={`Within ${data.warranty_expiring.days} days`}
                tone={data.warranty_expiring.count > 0 ? 'warning' : 'neutral'}
                onClick={() => go(`warranty_expiring=${data.warranty_expiring.days}`)}
              />
              <MetricCard
                label="Not placed in a room"
                value={data.summary.unassigned_location}
                hint="Assets with no location recorded"
                tone={data.summary.unassigned_location > 0 ? 'warning' : 'neutral'}
              />
            </div>
          </section>

          <section aria-labelledby="asset-distributions" className="flex flex-col gap-4">
            <h2 id="asset-distributions" className="text-lg font-bold text-ink-strong">
              Where the equipment is
            </h2>
            <div className="grid gap-6 lg:grid-cols-2 2xl:grid-cols-4">
              <DistributionCard
                title="By status"
                unit="assets"
                empty="Nothing is registered yet."
                rows={data.by_status}
                onSelect={(row: DistributionRow) => go(`status=${row.key}`)}
              />
              <DistributionCard
                title="By building"
                unit="assets"
                empty="No assets are placed in a building yet."
                rows={data.by_building}
                onSelect={(row: DistributionRow) => row.key && go(`building=${row.key}`)}
              />
              <DistributionCard
                title="By room"
                unit="assets"
                empty="No assets are placed in a room yet."
                rows={data.by_room}
                onSelect={(row: DistributionRow) => row.key && go(`room=${row.key}`)}
              />
              <DistributionCard
                title="By category"
                unit="assets"
                empty="No categories are in use yet."
                rows={data.by_category}
                onSelect={(row: DistributionRow) => row.key && go(`category=${row.key}`)}
              />
            </div>
          </section>

          <section aria-labelledby="asset-warranty" className="flex flex-col gap-4">
            <h2 id="asset-warranty" className="text-lg font-bold text-ink-strong">
              Warranty expiring soon
            </h2>
            {data.warranty_expiring.items.length === 0 ? (
              <p className="text-base text-muted">
                No warranties lapse in the next {data.warranty_expiring.days} days.
              </p>
            ) : (
              <div className="rounded-lg border-2 border-border bg-surface">
                <Table>
                  <THead>
                    <Tr>
                      <Th>Asset</Th>
                      <Th>Room</Th>
                      <Th>Expires</Th>
                      <Th className="text-right">Days left</Th>
                    </Tr>
                  </THead>
                  <TBody>
                    {data.warranty_expiring.items.map((item) => (
                      <Tr key={item.id} onClick={() => navigate(`/app/assets/${item.id}`)}>
                        <Td>
                          <span className="font-mono text-sm">{item.asset_tag}</span>
                          <span className="block text-sm text-muted">{item.name}</span>
                        </Td>
                        <Td className="text-sm">{item.room ?? '—'}</Td>
                        <Td className="text-sm tnum">{formatDate(item.warranty_expiration)}</Td>
                        <Td className="text-right text-sm font-semibold tnum text-warning-strong">
                          {item.days_remaining}
                        </Td>
                      </Tr>
                    ))}
                  </TBody>
                </Table>
              </div>
            )}
          </section>

          <div className="grid gap-6 xl:grid-cols-2">
            <RecentList
              title="Recently added"
              empty="Nothing has been added yet."
              assets={data.recently_added}
              onOpen={(id) => navigate(`/app/assets/${id}`)}
            />
            <RecentList
              title="Recently updated"
              empty="No recent changes."
              assets={data.recently_updated}
              onOpen={(id) => navigate(`/app/assets/${id}`)}
            />
          </div>
        </>
      )}

      {creating && (
        <AssetFormDrawer
          open={creating}
          onClose={() => setCreating(false)}
          onSaved={(id) => {
            setCreating(false)
            navigate(`/app/assets/${id}`)
          }}
        />
      )}
    </div>
  )
}

function RecentList({
  title,
  empty,
  assets,
  onOpen,
}: {
  title: string
  empty: string
  assets: RecentAsset[]
  onOpen: (id: string) => void
}) {
  return (
    <section className="rounded-lg border-2 border-border bg-surface p-6">
      <h3 className="text-base font-bold text-ink-strong">{title}</h3>

      {assets.length === 0 ? (
        <p className="mt-4 text-sm text-muted">{empty}</p>
      ) : (
        <ul className="mt-4 flex flex-col divide-y divide-border">
          {assets.map((asset) => (
            <li key={asset.id} className="py-3">
              <button
                type="button"
                onClick={() => onOpen(asset.id)}
                className="flex w-full flex-wrap items-center justify-between gap-3 rounded-md text-left"
              >
                <span>
                  <span className="block text-sm font-semibold text-primary-strong">
                    {asset.name}
                  </span>
                  <span className="block font-mono text-xs text-muted">{asset.asset_tag}</span>
                </span>
                <span className="flex items-center gap-3">
                  <AssetStatusBadge label={asset.status_label} tone={asset.tone} />
                  <span className="text-xs text-muted">{formatRelative(asset.at)}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 8 }).map((_, index) => (
          <Skeleton key={index} className="h-32 rounded-lg" />
        ))}
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <Skeleton className="h-72 rounded-lg" />
        <Skeleton className="h-72 rounded-lg" />
      </div>
    </div>
  )
}
