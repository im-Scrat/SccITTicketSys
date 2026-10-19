import { Megaphone } from 'lucide-react'
import { useState } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { Skeleton } from '@/components/ui/Skeleton'
import { Surface } from '@/components/ui/Surface'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { AnnouncementCard } from '../components/AnnouncementCard'
import { useAnnouncements } from '../hooks/queries'

/**
 * The announcement reader (SRS FR-NOT-011; decision D2).
 *
 * The dashboard has shown the three most recent announcements since Phase 2.4,
 * which is a summary rather than a reader: the fourth announcement was silently
 * dropped, and there was nowhere to go and find it. This is that place — the
 * complete, paginated list, scoped to the reader's audience by the server.
 *
 * **Every role reaches this page and no permission gates it.** An announcement
 * is addressed to people because of the role they hold, so the authorization is
 * audience membership, applied server-side; the client sends no filter of its
 * own and could not widen the set if it tried. That is the same shape
 * `/app/notifications` uses, and for the same reason.
 */
export default function AnnouncementsPage() {
  useDocumentMeta({ title: 'Announcements' })

  const [page, setPage] = useState(1)
  const announcements = useAnnouncements(page)

  const rows = announcements.data?.data ?? []
  const meta = announcements.data?.meta

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">Announcements</h1>
        <p className="mt-1 text-sm text-muted">
          Notices published for your role. Pinned announcements appear first.
        </p>
      </div>

      {announcements.isError && (
        <Alert tone="error" title="Announcements could not be loaded">
          <p>This is a display problem — nothing has been lost.</p>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="mt-3"
            onClick={() => void announcements.refetch()}
          >
            Try again
          </Button>
        </Alert>
      )}

      {announcements.isPending && (
        <div className="flex flex-col gap-4" role="status" aria-label="Loading announcements">
          {Array.from({ length: 3 }).map((_, index) => (
            <Surface key={index} className="p-5">
              <Skeleton height={14} width="45%" />
              <Skeleton height={10} width="90%" className="mt-3" />
              <Skeleton height={10} width="70%" className="mt-2" />
            </Surface>
          ))}
        </div>
      )}

      {!announcements.isPending && !announcements.isError && rows.length === 0 && (
        <EmptyState
          icon={<Megaphone size={22} />}
          title="No announcements right now"
          description="Notices published for your role appear here — the newest first, with pinned ones at the top."
        />
      )}

      {rows.length > 0 && (
        <div className="flex flex-col gap-4">
          {rows.map((announcement) => (
            <AnnouncementCard key={announcement.id} announcement={announcement} />
          ))}
        </div>
      )}

      {meta && rows.length > 0 && (
        <Surface className="overflow-hidden">
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            from={meta.from}
            to={meta.to}
            onPage={setPage}
          />
        </Surface>
      )}
    </div>
  )
}
