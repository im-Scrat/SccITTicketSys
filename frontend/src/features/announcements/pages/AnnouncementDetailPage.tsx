import { ArrowLeft, Megaphone } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { Alert } from '@/components/ui/Alert'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { Surface } from '@/components/ui/Surface'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { AnnouncementCard } from '../components/AnnouncementCard'
import { useAnnouncement } from '../hooks/queries'

/**
 * One announcement (SRS FR-NOT-011; Client decision D3).
 *
 * This route exists because the notification carries a destination. D3 chose a
 * detail page over a bare list, so `action_url` is
 * `/app/announcements/{uuid}` — and a link that resolves to nothing is worse
 * than no link, which is why the route is registered rather than the reader
 * list being reused for both.
 *
 * ── A refusal is an answer, not an error ──────────────────────────────────
 *
 * The server returns **403** when the caller is outside the announcement's
 * audience, and that is a legitimate outcome rather than a fault: audience
 * targeting is an authorization boundary. So the query does not retry, and the
 * page says plainly that the announcement is not for this reader instead of
 * showing a spinner that never resolves or a stack trace. It deliberately does
 * **not** distinguish "not yours" from "does not exist" — telling a reader that
 * an announcement they may not see exists would leak the fact of it.
 */
export default function AnnouncementDetailPage() {
  const { id = '' } = useParams<{ id: string }>()
  const announcement = useAnnouncement(id)

  useDocumentMeta({ title: announcement.data?.title ?? 'Announcement' })

  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <Link
        to="/app/announcements"
        className="inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-primary-strong underline-offset-2 hover:underline"
      >
        <ArrowLeft size={16} aria-hidden="true" />
        All announcements
      </Link>

      {announcement.isPending && (
        <div role="status" aria-label="Loading announcement">
          <Surface className="p-5">
            <Skeleton height={16} width="55%" />
            <Skeleton height={10} width="90%" className="mt-4" />
            <Skeleton height={10} width="75%" className="mt-2" />
          </Surface>
        </div>
      )}

      {announcement.isError && (
        <EmptyState
          icon={<Megaphone size={22} />}
          title="This announcement is not available to you"
          description="It may have been withdrawn, its display window may have passed, or it was addressed to a different group."
        />
      )}

      {announcement.data && <AnnouncementCard announcement={announcement.data} />}

      {announcement.data && (
        <Alert tone="info">
          Announcements are published by administrators. Reply to them through the usual channels —
          this page is a notice board, not a conversation.
        </Alert>
      )}
    </div>
  )
}
