import { PackageCheck } from 'lucide-react'
import { useState } from 'react'
import { Alert, Badge, EmptyState, Pagination, Skeleton, Surface } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { useMyAssets } from '../hooks/queries'

/**
 * "What am I the custodian of?" — the one asset surface a Technician, or an
 * Administrator checking their own custody, reaches without the `assets.view`
 * permission (SDD DD-38). The backend scopes `/my/assets` to
 * `assigned_technician_id = me`, so unlike the Admin-only directory this page
 * needs no permission guard: ownership is the boundary, enforced server-side,
 * the same stance {@link NotificationsPage} takes for notifications.
 */
export default function MyAssetsPage() {
  useDocumentMeta({ title: 'My assets' })

  const [page, setPage] = useState(1)
  const { data, isLoading, isError } = useMyAssets(page)

  const assets = data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">My assets</h1>
        <p className="mt-2 text-base text-muted">
          Equipment currently assigned to you as custodian.
        </p>
      </header>

      {isError && (
        <Alert tone="error" title="Your assets could not be loaded">
          Refresh the page to try again.
        </Alert>
      )}

      {isLoading ? (
        <div className="flex flex-col gap-3">
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
        </div>
      ) : isError ? null : assets.length === 0 ? (
        <EmptyState
          icon={<PackageCheck className="size-6" aria-hidden="true" />}
          title="No equipment is assigned to you"
          description="Equipment an administrator puts in your care appears here, with its tag and where it is."
        />
      ) : (
        <div className="flex flex-col gap-3">
          {assets.map((asset) => (
            <Surface
              key={asset.id}
              className="flex flex-wrap items-center justify-between gap-3 p-4"
            >
              <div>
                <div className="flex items-center gap-2">
                  <span className="font-medium text-ink-strong">{asset.name}</span>
                  <Badge tone="primary">Custodian: You</Badge>
                  <Badge tone="outline">{asset.status_label}</Badge>
                </div>
                <p className="mt-1 text-sm text-muted">
                  {asset.asset_tag}
                  {asset.room ? ` · ${asset.room.name}` : ''}
                  {asset.building ? `, ${asset.building.name}` : ''}
                </p>
              </div>
            </Surface>
          ))}
        </div>
      )}

      {data && (
        <Pagination
          page={page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          from={data.meta.from}
          to={data.meta.to}
          onPage={setPage}
        />
      )}
    </div>
  )
}
