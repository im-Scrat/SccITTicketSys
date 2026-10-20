import { CheckCircle2, MapPin, PackageCheck } from 'lucide-react'
import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  ConfirmDialog,
  EmptyState,
  LiveRegion,
  Pagination,
  Skeleton,
  Surface,
} from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { cn } from '@/lib/cn'
import { useConfirmAllPlacements, useConfirmPlacement } from '../hooks/mutations'
import { useMyAssets } from '../hooks/queries'
import type { AssetListItem } from '../types'

/**
 * "What am I the custodian of?" — the one asset surface a Technician, or an
 * Administrator checking their own custody, reaches without the `assets.view`
 * permission (SDD DD-38). The backend scopes `/my/assets` to
 * `assigned_technician_id = me`, so unlike the Admin-only directory this page
 * needs no permission guard: ownership is the boundary, enforced server-side,
 * the same stance {@link NotificationsPage} takes for notifications.
 *
 * **Confirming placement** (owner request 2026-10-20, SDD DD-115): once the
 * custodian has put an asset in its room, they click its panel — or "Confirm
 * all" — and it appears on that room's Floorplan for an administrator to
 * arrange. Confirming is safe to repeat, so a panel confirms on click with no
 * second question; "Confirm all" asks first, because it reaches every page.
 * An asset with no room cannot be confirmed, and its panel says why.
 */
export default function MyAssetsPage() {
  useDocumentMeta({ title: 'My assets' })

  const [page, setPage] = useState(1)
  const [confirmingAll, setConfirmingAll] = useState(false)
  const [announcement, setAnnouncement] = useState('')
  const { data, isLoading, isError } = useMyAssets(page)
  const confirmOne = useConfirmPlacement()
  const confirmAll = useConfirmAllPlacements()

  const assets = data?.data ?? []
  const waiting = assets.filter((asset) => asset.room && !asset.placement_confirmed_at).length

  const confirm = (asset: AssetListItem) => {
    confirmOne.mutate(asset.id, {
      onSuccess: () =>
        setAnnouncement(
          `${asset.name} confirmed in ${asset.room?.name ?? 'its room'}. It now appears on that room’s Floorplan.`,
        ),
    })
  }

  return (
    <div className="flex flex-col gap-8">
      <LiveRegion message={announcement} />

      <header className="flex flex-wrap items-end justify-between gap-4">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">My assets</h1>
          <p className="mt-2 text-base text-muted">
            Equipment currently assigned to you as custodian. Once you have put an item in its room,
            click it to confirm — it then appears on that room’s Floorplan.
          </p>
        </div>
        {assets.length > 0 && (
          <Button
            variant="secondary"
            leftIcon={<CheckCircle2 className="size-4" aria-hidden="true" />}
            onClick={() => setConfirmingAll(true)}
          >
            Confirm all
          </Button>
        )}
      </header>

      {isError && (
        <Alert tone="error" title="Your assets could not be loaded">
          Refresh the page to try again.
        </Alert>
      )}

      {confirmOne.isError && (
        <Alert tone="error" title="That asset could not be confirmed">
          {getErrorMessage(confirmOne.error)}
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
        <ul className="flex flex-col gap-3" aria-label="Assets in your care">
          {assets.map((asset) => (
            <li key={asset.id}>
              <AssetPanel
                asset={asset}
                pending={confirmOne.isPending && confirmOne.variables === asset.id}
                onConfirm={() => confirm(asset)}
              />
            </li>
          ))}
        </ul>
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

      <ConfirmDialog
        open={confirmingAll}
        onClose={() => {
          setConfirmingAll(false)
          confirmAll.reset()
        }}
        onConfirm={() =>
          confirmAll.mutate(undefined, {
            onSuccess: (result) => {
              setConfirmingAll(false)
              const { confirmed, without_room: roomless } = result.meta
              setAnnouncement(
                confirmed === 0
                  ? 'Nothing left to confirm.'
                  : `${confirmed} asset${confirmed === 1 ? '' : 's'} confirmed in their rooms.` +
                      (roomless > 0
                        ? ` ${roomless} with no room yet ${roomless === 1 ? 'was' : 'were'} left as ${roomless === 1 ? 'it was' : 'they were'}.`
                        : ''),
              )
            },
          })
        }
        loading={confirmAll.isPending}
        title="Confirm all your assets are in their rooms?"
        confirmLabel="Confirm all"
        description={
          waiting > 0 || data?.meta.last_page !== 1
            ? 'Every asset assigned to you that has a room is marked as in place, on every page, and appears on its room’s Floorplan. Assets with no room yet are left as they are.'
            : 'Everything on this page is already confirmed or has no room yet. Assets on other pages are included.'
        }
      >
        {confirmAll.isError && <Alert tone="error">{getErrorMessage(confirmAll.error)}</Alert>}
      </ConfirmDialog>
    </div>
  )
}

function AssetPanel({
  asset,
  pending,
  onConfirm,
}: {
  asset: AssetListItem
  pending: boolean
  onConfirm: () => void
}) {
  const where = asset.room
    ? `${asset.room.name}${asset.building ? `, ${asset.building.name}` : ''}`
    : 'No room'
  const confirmed = Boolean(asset.placement_confirmed_at)
  const confirmable = Boolean(asset.room) && !confirmed

  const body = (
    <>
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium text-ink-strong">{asset.name}</span>
          <Badge tone="primary">Custodian: You</Badge>
          <Badge tone="outline">{asset.status_label}</Badge>
        </div>
        <p className="mt-1 inline-flex items-center gap-1 text-sm text-muted">
          <MapPin className="size-3.5 shrink-0" aria-hidden="true" />
          {where}
        </p>
      </div>
      <span className="shrink-0 text-sm">
        {confirmed ? (
          <Badge tone="success" icon={<CheckCircle2 className="size-3.5" aria-hidden="true" />}>
            Confirmed in room
          </Badge>
        ) : !asset.room ? (
          <span className="text-muted">An administrator records its room first</span>
        ) : (
          <span className="font-semibold text-primary-strong">
            {pending ? 'Confirming…' : 'Click to confirm it is in the room'}
          </span>
        )}
      </span>
    </>
  )

  const shell = 'flex w-full flex-wrap items-center justify-between gap-3 p-4 text-left'

  if (!confirmable) {
    return <Surface className={shell}>{body}</Surface>
  }

  return (
    <button
      type="button"
      onClick={onConfirm}
      disabled={pending}
      aria-label={`Confirm ${asset.name} is in ${where}`}
      className={cn(
        shell,
        'rounded-lg border-2 border-border bg-surface transition-colors hover:border-primary hover:bg-primary-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-progress disabled:opacity-70',
      )}
    >
      {body}
    </button>
  )
}
